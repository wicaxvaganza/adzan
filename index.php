<?php  
@ini_set('display_errors', '0');
@error_reporting(0);

/* ========= CONFIG ========= */
$LOG_PATH        = __DIR__ . '/adzan_activity.log';
$CLIENT_LOG_PATH = __DIR__ . '/client_error.log';
$MAX_LOG_LINES   = 50;
$MAX_LOG_LINE_LENGTH = 500;

/* ========= Helper: timestamp Jakarta ========= */
function get_jakarta_ts($ts = null) {
    try {
        if ($ts === null) {
            $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        } else {
            $dt = new DateTime('@' . intval($ts));
            $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        }
        return $dt->format('d-m-Y H:i:s');
    } catch (Exception $e) {
        return date('d-m-Y H:i:s');
    }
}

/* ========= Helper: sanitize log ========= */
function sanitize_log_line($line, $max_len = 500) {
    $line = preg_replace('/[^\PC\s]/u', '', $line);
    if (stripos($line, '<?php') !== false || stripos($line, '<!doctype') !== false || stripos($line, '<html') !== false) {
        $line = '[skipped large HTML/PHP content] ' . preg_replace('/\s+/', ' ', trim($line));
    }
    $line = str_replace(array("\r", "\n", "\t"), ' ', $line);
    $line = preg_replace('/\s+/', ' ', $line);
    $line = trim($line);
    if (mb_strlen($line) > $max_len) {
        $line = mb_substr($line, 0, $max_len - 3) . '...';
    }
    return $line;
}

/* ========= Log: append & read ========= */
function append_log_line($path, $line, $max_lines = 50, $max_len = 500) {
    $safe = sanitize_log_line($line, $max_len);
    $fh = @fopen($path, 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    fseek($fh, 0);
    $content = stream_get_contents($fh);
    $lines = ($content === false || trim($content) === '') ? array() : preg_split("/\r\n|\n|\r/", trim($content));
    if (count($lines) === 1 && $lines[0] === '') $lines = array();
    array_unshift($lines, $safe);
    $lines = array_slice($lines, 0, $max_lines);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, implode(PHP_EOL, $lines) . PHP_EOL);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}

function read_log_lines($path, $max_lines = 50) {
    if (!file_exists($path)) return array();
    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') return array();
    $lines = preg_split("/\r\n|\n|\r/", trim($content));
    if (count($lines) === 1 && $lines[0] === '') return array();
    return array_slice($lines, 0, $max_lines);
}

/* ========= ENDPOINT: viewlog ========= */
if (isset($_GET['viewlog'])) {
    header('Content-Type: application/json; charset=utf-8');
    $lines = read_log_lines($LOG_PATH, $MAX_LOG_LINES);
    $out = @json_encode(array('lines' => $lines), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($out === false) {
        $safe = array();
        foreach ($lines as $l) {
            $safe[] = mb_convert_encoding($l, 'UTF-8', 'UTF-8');
        }
        echo json_encode(array('lines' => $safe), JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo $out;
    exit;
}

/* ========= ENDPOINT: clientlog ========= */
if (isset($_GET['clientlog'])) {
    $msg = isset($_GET['msg']) ? (string)$_GET['msg'] : 'clientlog';
    $ip  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $line = get_jakarta_ts() . " | $ip | CLIENT | " . sanitize_log_line($msg, 500);

    @file_put_contents($CLIENT_LOG_PATH, $line . PHP_EOL, FILE_APPEND);
    append_log_line($LOG_PATH, $line, $MAX_LOG_LINES, $MAX_LOG_LINE_LENGTH);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => true));
    exit;
}

/* ========= ENDPOINT: Adzan proxy (Banyuwangi Kota) + cache harian =========
   (kalau ini gagal, front-end akan fallback langsung ke API MyQuran) */
if (isset($_GET['adzan'])) {
    header('Content-Type: application/json; charset=utf-8');

    $KOTA_ID_BANYUWANGI = '1602';

    try {
        $dtJakarta = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    } catch (Exception $e) {
        $dtJakarta = new DateTime('now');
    }
    $dateKey = $dtJakarta->format('Y-m-d');
    $year    = $dtJakarta->format('Y');
    $month   = $dtJakarta->format('m');
    $day     = $dtJakarta->format('d');

    $cacheDir = __DIR__ . '/cache_adzan';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    $cacheFile = $cacheDir . "/adzan_banyuwangi_" . $dateKey . ".json";

    // pakai cache kalau masih ada & valid
    if (file_exists($cacheFile)) {
        $cached = @file_get_contents($cacheFile);
        if ($cached !== false && trim($cached) !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && isset($decoded['data']['timings'])) {
                echo $cached;
                exit;
            }
        }
    }

    // coba pakai cURL kalau ada
    $result  = false;
    $code    = 0;
    $curlErr = '';
    $url = "https://api.myquran.com/v2/sholat/jadwal/"
         . rawurlencode($KOTA_ID_BANYUWANGI)
         . "/$year/$month/$day";

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $result  = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
    }

    // fallback: file_get_contents jika cURL gagal atau tidak ada
    if (($code !== 200 || !$result) && function_exists('file_get_contents')) {
        $ctx = stream_context_create(array(
            'http' => array(
                'method'  => 'GET',
                'timeout' => 15,
                'header'  => "User-Agent: Mozilla/5.0\r\n"
            ),
            'ssl' => array(
                'verify_peer'      => false,
                'verify_peer_name' => false
            )
        ));
        $result = @file_get_contents($url, false, $ctx);
        $code   = $result ? 200 : 0;
    }

    if ($code !== 200 || !$result) {
        append_log_line(
            $LOG_PATH,
            get_jakarta_ts() . " | ADZAN_ERR | HTTP:" . $code . " | CURL:" . sanitize_log_line($curlErr, 200),
            $MAX_LOG_LINES,
            $MAX_LOG_LINE_LENGTH
        );

        http_response_code(502);
        echo json_encode(array(
            "ok"    => false,
            "error" => "Failed to fetch adzan timings (PHP)",
            "code"  => $code,
            "curl"  => $curlErr
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $decoded = json_decode($result, true);
    if (!is_array($decoded) || !isset($decoded['data']['jadwal']) || !is_array($decoded['data']['jadwal'])) {
        http_response_code(502);
        echo json_encode(array(
            "ok"    => false,
            "error" => "Invalid response format from MyQuran API"
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $jadwal = $decoded['data']['jadwal'];
    $normalized = array(
        "status" => true,
        "source" => "myquran",
        "data"   => array(
            "timings" => array(
                "Fajr"    => isset($jadwal['subuh'])   ? $jadwal['subuh']   : null,
                "Dhuhr"   => isset($jadwal['dzuhur'])  ? $jadwal['dzuhur']  : null,
                "Asr"     => isset($jadwal['ashar'])   ? $jadwal['ashar']   : null,
                "Maghrib" => isset($jadwal['maghrib']) ? $jadwal['maghrib'] : null,
                "Isha"    => isset($jadwal['isya'])    ? $jadwal['isya']    : null
            ),
            "jadwal" => $jadwal,
            "date"   => isset($jadwal['date']) ? $jadwal['date'] : $dateKey
        )
    );
    $normalizedJson = json_encode($normalized, JSON_UNESCAPED_UNICODE);
    if ($normalizedJson !== false) {
        @file_put_contents($cacheFile, $normalizedJson, LOCK_EX);
        echo $normalizedJson;
        exit;
    }

    echo $result;
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Bot Adzan Banyuwangi</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="max-w-3xl mx-auto p-5">
  <div class="flex items-start gap-3">
    <div>
      <h1 class="text-xl font-semibold">Bot Adzan Banyuwangi</h1>
      <p class="text-slate-500 text-sm">
        Klik <strong>Start</strong> sekali agar browser memberi izin suara. Biarkan tab terbuka.
        File <code>adzan.mp3</code>, <code>subuh.mp3</code>, <code>sudah.mp3</code>, <code>asing.mp3</code>, <code>umbul.mp3</code>, dan <code>kahfi.mp3</code> harus ada di folder yang sama dengan file ini.
      </p>
    </div>
    <div class="ml-auto flex items-center gap-2">
      <span class="text-slate-500 text-sm">Status:</span>
      <span id="statusBadge" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-slate-700 text-sm">
        Stopped
      </span>
    </div>
  </div>

  <!-- Tombol & jam -->
  <div class="mt-4 flex flex-wrap items-center gap-2">
    <button id="btnStart" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-[15px] shadow-sm hover:bg-slate-50">
      Start
    </button>
    <button id="btnStop" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-[15px] shadow-sm hover:bg-slate-50 hidden">
      Stop
    </button>

    <button id="btnTestAdzan" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm hover:bg-slate-100">
      Tes adzan.mp3
    </button>
    <button id="btnTestSubuh" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm hover:bg-slate-100">
      Tes subuh.mp3
    </button>
    <button id="btnTestDoa" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm hover:bg-slate-100">
      Tes sudah.mp3
    </button>

    <span class="text-sm text-slate-600 ml-1">
      • <span id="liveClock" class="font-medium text-slate-800">--:--:--</span>
    </span>
  </div>

  <!-- Tes Presensi Pulang -->
  <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3">
    <div class="text-sm text-slate-700">
      Presensi Pulang:
      autoplay <code>asing.mp3</code> di jam pulang -5 menit, lalu <code>umbul.mp3</code> di jam pulang.
      Sen-Kam 14:00, Jumat 11:00, Sabtu 12:30.
    </div>
    <div class="mt-2 flex items-center gap-2">
      <button id="btnTestAsing" class="rounded-md border border-cyan-200 bg-cyan-50 px-3 py-1.5 text-sm hover:bg-cyan-100">
        Test asing.mp3
      </button>
      <span id="asingTestState" class="text-xs text-slate-500">Idle</span>
      <button id="btnTestUmbul" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-sm hover:bg-emerald-100">
        Test umbul.mp3
      </button>
      <span id="umbulTestState" class="text-xs text-slate-500">Idle</span>
    </div>
  </div>

  <!-- Tes Jumat Kahfi -->
  <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3">
    <div class="text-sm text-slate-700">
      Jumat: autoplay <code>kahfi.mp3</code> pada H-60 menit sebelum Dzuhur (ambil dari API jadwal adzan hari itu).
    </div>
    <div class="mt-2 flex items-center gap-2">
      <button id="btnTestKahfi" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-1.5 text-sm hover:bg-amber-100">
        Test kahfi.mp3
      </button>
      <span id="kahfiTestState" class="text-xs text-slate-500">Idle</span>
    </div>
  </div>

  <!-- Countdown -->
  <div class="mt-4 flex items-center gap-3">
    <span class="text-slate-500 text-sm">Countdown adzan berikutnya:</span>
    <span id="countdown" class="font-bold text-lg text-emerald-700">--:--:--</span>
  </div>
  <div class="mt-1 text-slate-500 text-sm">
    Adzan berikutnya: <span id="nextEventLabel" class="font-medium text-slate-700">-</span>
  </div>
  <div class="mt-1 text-slate-500 text-sm">
    Mode: <span id="modeInfo" class="font-medium text-slate-700">Adzan + doa (mp3)</span>
  </div>

  <!-- Jadwal sholat -->
  <div class="mt-6">
    <div class="flex items-center justify-between gap-3">
      <h3 class="font-semibold">Jadwal Adzan Hari Ini — Banyuwangi Kota</h3>
      <div class="text-xs text-slate-500">
        Terakhir diperbarui: <span id="sholatLastUpdate">-</span>
      </div>
    </div>
    <div id="sholatList" class="mt-2 flex flex-col gap-2"></div>
  </div>

  <!-- Log -->
  <div class="mt-6">
    <div class="text-slate-500 text-sm mb-1">
      Log server (terbaru paling atas, maksimal <?php echo $MAX_LOG_LINES; ?> baris):
    </div>
    <div id="log" class="max-h-56 overflow-auto whitespace-pre-wrap rounded-lg border border-slate-200 bg-slate-50 p-3 font-mono text-xs text-slate-700">
      Memuat log...
    </div>
    <div class="mt-1 text-slate-400 text-xs">
      Log di-refresh otomatis tiap 10 detik.
    </div>
  </div>
</div>

<script>
(function(){

  // ===== KONFIGURASI SHOLAT =====
  var KOTA_ID_BANYUWANGI = '1602';
  var SHOLAT_ORDER = ['Fajr','Dhuhr','Asr','Maghrib','Isha'];
  var SHOLAT_LABEL = {
    Fajr:    'Subuh',
    Dhuhr:   'Dzuhur',
    Asr:     'Ashar',
    Maghrib: 'Maghrib',
    Isha:    'Isya'
  };

  // Ambil elemen DOM
  var btnStart      = document.getElementById('btnStart');
  var btnStop       = document.getElementById('btnStop');
  var btnTestAdzan  = document.getElementById('btnTestAdzan');
  var btnTestSubuh  = document.getElementById('btnTestSubuh');
  var btnTestDoa    = document.getElementById('btnTestDoa');
  var btnTestAsing  = document.getElementById('btnTestAsing');
  var btnTestUmbul  = document.getElementById('btnTestUmbul');
  var btnTestKahfi  = document.getElementById('btnTestKahfi');
  var asingTestStateEl = document.getElementById('asingTestState');
  var umbulTestStateEl = document.getElementById('umbulTestState');
  var kahfiTestStateEl = document.getElementById('kahfiTestState');
  var statusBadge   = document.getElementById('statusBadge');
  var liveClockEl   = document.getElementById('liveClock');
  var countdownEl   = document.getElementById('countdown');
  var nextEventEl   = document.getElementById('nextEventLabel');
  var modeInfo      = document.getElementById('modeInfo');
  var sholatListEl  = document.getElementById('sholatList');
  var sholatLastUpd = document.getElementById('sholatLastUpdate');
  var logEl         = document.getElementById('log');

  // ===== STATE =====
  var started               = false;
  var playing               = false;
  var sholatTimings         = null;    // {Fajr:'04:xx', ...}
  var playedToday           = {};      // { 'YYYY-MM-DD': {Fajr:1, ...} }
  var checkTimerId          = null;
  var countdownTimerId      = null;
  var nextRunTimestamp      = null;
  var logTimerId            = null;
  var scheduleRefreshTimerId = null;   // <- interval refresh jadwal
  var asingPlayedToday      = {};      // {'YYYY-MM-DD': {'presensi-minus5':1}}
  var umbulPlayedToday      = {};      // {'YYYY-MM-DD': {'presensi-pulang':1}}
  var kahfiPlayedToday      = {};      // {'YYYY-MM-DD': {'jumat-kahfi':1}}
  var adzanTestAudio        = null;
  var subuhTestAudio        = null;
  var doaTestAudio          = null;
  var asingTestAudio        = null;
  var umbulTestAudio        = null;
  var kahfiTestAudio        = null;

  // ===== Helper tanggal =====
  function todayKey(){
    var d = new Date();
    var yy = d.getFullYear();
    var mm = String(d.getMonth() + 1).padStart(2, '0');
    var dd = String(d.getDate()).padStart(2, '0');
    return yy + '-' + mm + '-' + dd;
  }

  function getPresensiPulangTime(now){
    var d = now.getDay(); // 0=Min, 1=Sen, ... 6=Sab
    if(d >= 1 && d <= 4) return '14:00'; // Sen-Kam
    if(d === 5) return '11:00';          // Jumat
    if(d === 6) return '12:30';          // Sabtu
    return null;                         // Minggu: tidak ada
  }

  function minusMinutesHHMM(hhmm, minutes){
    if(!hhmm) return null;
    var p = String(hhmm).split(':');
    if(p.length < 2) return null;
    var h = parseInt(p[0], 10);
    var m = parseInt(p[1], 10);
    if(isNaN(h) || isNaN(m)) return null;
    var total = (h * 60 + m) - minutes;
    while(total < 0) total += (24 * 60);
    var hh = String(Math.floor(total / 60) % 24).padStart(2, '0');
    var mm = String(total % 60).padStart(2, '0');
    return hh + ':' + mm;
  }

  function getJumatKahfiTime(now){
    if(!now || now.getDay() !== 5) return null; // khusus Jumat
    if(!sholatTimings || !sholatTimings.Dhuhr) return null;

    var p = String(sholatTimings.Dhuhr).split(':');
    if(p.length < 2) return null;
    var h = parseInt(p[0], 10);
    var m = parseInt(p[1], 10);
    if(isNaN(h) || isNaN(m)) return null;

    var total = (h * 60 + m) - 60; // H-60 menit sebelum Dzuhur
    while(total < 0) total += (24 * 60);
    var hh = String(Math.floor(total / 60) % 24).padStart(2, '0');
    var mm = String(total % 60).padStart(2, '0');
    return hh + ':' + mm;
  }

  // ===== Jam live di header =====
  (function startLiveClock(){
    if(!liveClockEl) return;
    function tick(){
      var d = new Date();
      var hh = String(d.getHours()).padStart(2,'0');
      var mm = String(d.getMinutes()).padStart(2,'0');
      var ss = String(d.getSeconds()).padStart(2,'0');
      liveClockEl.textContent = hh + ':' + mm + ':' + ss;
    }
    tick();
    setInterval(tick, 1000);
  })();

  // ===== Player: main util untuk play beberapa file berurutan =====
  function playAudioFilesSequential(sources){
    return new Promise(function(resolve,reject){
      if(!sources || !sources.length){
        return resolve('no-src');
      }
      if(playing){
        return resolve('skip');
      }
      playing = true;
      var index = 0;

      function playNext(){
        if(index >= sources.length){
          playing = false;
          resolve('ok');
          return;
        }
        var src = sources[index];
        var audio = new Audio(src);
        audio.preload = 'auto';

        audio.onended = function(){
          index++;
          playNext();
        };
        audio.onerror = function(e){
          playing = false;
          console.error('Gagal play audio', e);
          alert('Gagal memutar ' + src + ' (cek file & lokasi).');
          reject(e);
        };

        var p = audio.play();
        if(p && p.catch){
          p.catch(function(err){
            playing = false;
            console.error('Autoplay error', err);
            alert('Browser menolak autoplay. Klik halaman lalu tekan Start / ulangi.');
            reject(err);
          });
        }
      }

      playNext();
    });
  }

  // ===== Ambil jadwal adzan dari backend (?adzan=1) atau langsung API =====
  function loadSholatBanyuwangi(callback){
    // 1) coba ke backend PHP (?adzan=1)
    var xhr = new XMLHttpRequest();
    xhr.open('GET', window.location.pathname + '?adzan=1', true);
    xhr.onreadystatechange = function(){
      if(xhr.readyState === 4){
        if(xhr.status === 200){
          try{
            var j = JSON.parse(xhr.responseText);
            var t = j && j.data && j.data.timings;
            if(t){
              setTimingsFromObject(t);
              if(typeof callback === 'function') callback(true);
              return;
            }
          }catch(e){
            console.error('JSON parse error backend:', e);
          }
        }
        // kalau gagal atau JSON salah, fallback ke API langsung
        console.log('Backend ?adzan=1 gagal, fallback ke API MyQuran langsung...');
        fetchFromMyQuranDirect(callback);
      }
    };
    xhr.send();
  }

  // Fallback: langsung ke https://api.myquran.com/... dari browser
  function fetchFromMyQuranDirect(callback){
    var now = new Date();
    var year = now.getFullYear();
    var month = String(now.getMonth() + 1).padStart(2, '0');
    var day = String(now.getDate()).padStart(2, '0');
    var url = 'https://api.myquran.com/v2/sholat/jadwal/' + KOTA_ID_BANYUWANGI + '/' + year + '/' + month + '/' + day;
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function(){
      if(xhr.readyState === 4){
        if(xhr.status === 200){
          try{
            var j = JSON.parse(xhr.responseText);
            var t = j && j.data && j.data.jadwal;
            if(t){
              setTimingsFromObject({
                Fajr: t.subuh,
                Dhuhr: t.dzuhur,
                Asr: t.ashar,
                Maghrib: t.maghrib,
                Isha: t.isya
              });
              if(typeof callback === 'function') callback(true);
              return;
            }
            var tt = j && j.data && j.data.timings;
            if(tt){
              setTimingsFromObject(tt);
              if(typeof callback === 'function') callback(true);
              return;
            }
          }catch(e){
            console.error('JSON parse error direct API:', e);
          }
        }
        // kalau sampai sini, benar-benar gagal
        if(!sholatTimings){
          // hanya kalau memang belum ada jadwal sama sekali
          renderSholatListPlaceholder();
          setSholatLastUpdate(null);
        }
        if(typeof callback === 'function') callback(false);
      }
    };
    xhr.send();
  }

  function setTimingsFromObject(t){
    sholatTimings = {
      Fajr:    t.Fajr,
      Dhuhr:   t.Dhuhr,
      Asr:     t.Asr,
      Maghrib: t.Maghrib,
      Isha:    t.Isha
    };
    renderSholatList();
    setSholatLastUpdate(new Date());
    hitungNextRun();
  }

  // ===== Render jadwal ke UI =====
  function renderSholatListPlaceholder(){
    if(!sholatListEl) return;
    sholatListEl.innerHTML = '';
    SHOLAT_ORDER.forEach(function(k){
      var row  = document.createElement('div');
      row.className = 'flex items-center gap-2';
      var desc = document.createElement('div');
      desc.className = 'rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm';
      desc.textContent = '--:-- — Sholat ' + SHOLAT_LABEL[k] + ' (Banyuwangi)';
      row.appendChild(desc);
      sholatListEl.appendChild(row);
    });
  }

  function renderSholatList(){
    if(!sholatListEl) return;
    sholatListEl.innerHTML = '';
    SHOLAT_ORDER.forEach(function(k){
      var time = sholatTimings && sholatTimings[k] ? sholatTimings[k] : '--:--';
      var row  = document.createElement('div');
      row.className = 'flex items-center gap-2';
      var desc = document.createElement('div');
      desc.className = 'rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm';
      desc.textContent = time + ' — Sholat ' + SHOLAT_LABEL[k] + ' (Banyuwangi)';
      row.appendChild(desc);
      sholatListEl.appendChild(row);
    });
  }

  function setSholatLastUpdate(dateObj){
    if(!sholatLastUpd) return;
    if(!dateObj){
      sholatLastUpd.textContent = '-';
      return;
    }
    var d  = new Date(dateObj);
    var dd = String(d.getDate()).padStart(2,'0');
    var mm = String(d.getMonth()+1).padStart(2,'0');
    var yy = d.getFullYear();
    var hh = String(d.getHours()).padStart(2,'0');
    var mi = String(d.getMinutes()).padStart(2,'0');
    var ss = String(d.getSeconds()).padStart(2,'0');
    sholatLastUpd.textContent = dd + '-' + mm + '-' + yy + ' ' + hh + ':' + mi + ':' + ss + ' WIB';
  }

  // ===== Hitung jadwal adzan berikutnya (untuk countdown) =====
  function nextSholatTS(){
    if(!sholatTimings) return null;
    var now   = new Date();
    var nowMs = now.getTime();
    var list  = [];

    // cari jadwal sisa hari ini
    SHOLAT_ORDER.forEach(function(k){
      var hhmm = sholatTimings[k];
      if(!hhmm) return;
      var parts = hhmm.split(':');
      var H = parseInt(parts[0],10);
      var M = parseInt(parts[1],10);
      var ts = new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate(),
        H, M, 0, 0
      ).getTime();

      if(ts > nowMs + 10000){ // yang di depan sekarang + 10 detik
        list.push({
          key:   k,
          ts:    ts,
          label: 'Adzan ' + SHOLAT_LABEL[k]
        });
      }
    });

    // kalau masih ada jadwal hari ini
    if(list.length){
      list.sort(function(a,b){ return a.ts - b.ts; });
      return list[0];
    }

    // kalau tidak ada jadwal lagi hari ini, countdown ke Subuh besok
    var fajr = sholatTimings.Fajr;
    if(!fajr) return null;

    var p = fajr.split(':');
    var HF = parseInt(p[0],10);
    var MF = parseInt(p[1],10);

    var tsBesok = new Date(
      now.getFullYear(),
      now.getMonth(),
      now.getDate() + 1,
      HF, MF, 0, 0
    ).getTime();

    return {
      key:   'Fajr',
      ts:    tsBesok,
      label: 'Adzan ' + SHOLAT_LABEL['Fajr'] + ' (besok)'
    };
  }

  function hitungNextRun(){
    var nxt = nextSholatTS();
    if(!nxt){
      nextRunTimestamp = null;
      if(countdownEl) countdownEl.textContent = '--:--:--';
      if(nextEventEl) nextEventEl.textContent = '-';
      return;
    }
    nextRunTimestamp = nxt.ts;
    startCountdown(nxt.label);
  }

  function startCountdown(label){
    if(countdownTimerId) clearInterval(countdownTimerId);
    if(!countdownEl || !nextEventEl) return;

    countdownTimerId = setInterval(function(){
      if(!nextRunTimestamp){
        countdownEl.textContent = '--:--:--';
        nextEventEl.textContent = '-';
        return;
      }
      var ms = nextRunTimestamp - Date.now();
      if(ms <= 0){
        countdownEl.textContent = '00:00:00';
        nextEventEl.textContent = label || '-';
        // setelah sampai, hitung ulang ke jadwal berikutnya (termasuk Subuh besok)
        hitungNextRun();
        return;
      }
      var s  = Math.floor(ms/1000);
      var hh = String(Math.floor(s/3600)).padStart(2,'0');
      var mm = String(Math.floor((s%3600)/60)).padStart(2,'0');
      var ss = String(s%60).padStart(2,'0');
      countdownEl.textContent = hh + ':' + mm + ':' + ss;

      var dNext = new Date(nextRunTimestamp);
      var jamStr = String(dNext.getHours()).padStart(2,'0') + ':' + String(dNext.getMinutes()).padStart(2,'0');
      nextEventEl.textContent = (label || 'Adzan') + ' (' + jamStr + ')';
    }, 500);
  }

  // ===== Loop cek adzan: kalau jam & menit sama, play adzan + doa =====
  function checkAdzanLoop(){
    if(!started) return;

    var now = new Date();
    var hh  = String(now.getHours()).padStart(2,'0');
    var mm  = String(now.getMinutes()).padStart(2,'0');
    var cur = hh + ':' + mm;
    var tkey = todayKey();

    if(!playedToday[tkey]) playedToday[tkey] = {};
    if(!asingPlayedToday[tkey]) asingPlayedToday[tkey] = {};
    if(!umbulPlayedToday[tkey]) umbulPlayedToday[tkey] = {};
    if(!kahfiPlayedToday[tkey]) kahfiPlayedToday[tkey] = {};

    // Presensi pulang -5 menit: autoplay asing.mp3 sesuai hari
    var jamPulang = getPresensiPulangTime(now);
    var jamAsing = minusMinutesHHMM(jamPulang, 5);
    if(jamAsing === cur && !asingPlayedToday[tkey]['presensi-minus5']){
      playAudioFilesSequential(['asing.mp3']).then(function(res){
        if(res === 'ok'){
          asingPlayedToday[tkey]['presensi-minus5'] = 1;
          try {
            fetch(
              window.location.pathname +
              '?clientlog=1&msg=' +
              encodeURIComponent('PLAY PRESENSI MINUS5 asing.mp3 at ' + cur)
            ).catch(function(){});
          } catch(e) {}
        }
      }).catch(function(){});
    }

    // Presensi pulang: autoplay umbul.mp3 sesuai hari
    if(jamPulang === cur && !umbulPlayedToday[tkey]['presensi-pulang']){
      playAudioFilesSequential(['umbul.mp3']).then(function(res){
        if(res === 'ok'){
          umbulPlayedToday[tkey]['presensi-pulang'] = 1;
          try {
            fetch(
              window.location.pathname +
              '?clientlog=1&msg=' +
              encodeURIComponent('PLAY PRESENSI PULANG umbul.mp3 at ' + cur)
            ).catch(function(){});
          } catch(e) {}
        }
      }).catch(function(){});
    }

    if(!sholatTimings) return;

    // Jumat: H-60 sebelum Dzuhur, autoplay kahfi.mp3 (berdasarkan API Dhuhr)
    var jamKahfi = getJumatKahfiTime(now);
    if(jamKahfi === cur && !kahfiPlayedToday[tkey]['jumat-kahfi']){
      playAudioFilesSequential(['kahfi.mp3']).then(function(res){
        if(res === 'ok'){
          kahfiPlayedToday[tkey]['jumat-kahfi'] = 1;
          try {
            fetch(
              window.location.pathname +
              '?clientlog=1&msg=' +
              encodeURIComponent('PLAY JUMAT KAHFI kahfi.mp3 H-60 Dzuhur at ' + cur)
            ).catch(function(){});
          } catch(e) {}
        }
      }).catch(function(){});
    }

    SHOLAT_ORDER.forEach(function(k){
      var jam = sholatTimings[k];
      if(!jam) return;
      if(jam !== cur) return;
      if(playedToday[tkey][k]) return;

      var isSubuh = (k === 'Fajr');
      var firstSrc = isSubuh ? 'subuh.mp3' : 'adzan.mp3';

      // main sequence: adzan + doa (sudah.mp3)
      playAudioFilesSequential([firstSrc, 'sudah.mp3']).then(function(res){
        if(res !== 'ok') return;
        playedToday[tkey][k] = 1;
        hitungNextRun();

        // kirim log ke server — dipisah antara adzan & doa
        try {
          var base = window.location.pathname + '?clientlog=1&msg=';
          var kindLabel = isSubuh ? 'SUBUH' : 'ADZAN';

          // log adzan (jam jadwal, sesuai jadwal adzan)
          var msgAdzan = 'PLAY ' + kindLabel + ' ' + k + ' (adzan) at ' + cur;
          fetch(base + encodeURIComponent(msgAdzan)).catch(function(){});

          // log doa (jam aktual saat doa selesai diputar / log dikirim)
          var nowDoa = new Date();
          var hh2 = String(nowDoa.getHours()).padStart(2,'0');
          var mm2 = String(nowDoa.getMinutes()).padStart(2,'0');
          var curDoa = hh2 + ':' + mm2;

          var msgDoa = 'PLAY DOA setelah ' + k + ' (sudah.mp3) at ' + curDoa;
          fetch(base + encodeURIComponent(msgDoa)).catch(function(){});
        } catch(e) {}
      }).catch(function(e){
        console.error('Gagal play adzan/doa', e);
      });
    });
  }

  // ===== Log server =====
  function fetchLogs(){
    if(!logEl) return;
    var xhr = new XMLHttpRequest();
    xhr.open('GET', window.location.pathname + '?viewlog=1', true);
    xhr.onreadystatechange = function(){
      if(xhr.readyState === 4){
        if(xhr.status === 200){
          try{
            var j = JSON.parse(xhr.responseText);
            var lines = (j.lines || []);
            logEl.textContent = lines.join('\n');
          }catch(e){
            logEl.textContent = 'Gagal parse log: ' + e;
          }
        }else{
          logEl.textContent = 'Gagal memuat log (HTTP ' + xhr.status + ')';
        }
      }
    };
    xhr.send();
  }

  // ===== Start / Stop engine =====
  function startEngine(){
    if(started) return;
    started = true;

    if(btnStart) btnStart.classList.add('hidden');
    if(btnStop)  btnStop.classList.remove('hidden');
    if(statusBadge) statusBadge.textContent = 'Running';
    if(modeInfo) modeInfo.textContent = 'Adzan + doa (mp3)';

    renderSholatListPlaceholder();

    // load pertama kali
    loadSholatBanyuwangi(function(ok){
      if(!ok){
        alert('Gagal mengambil jadwal adzan dari backend & API. Cek koneksi internet.');
      }
      hitungNextRun();
    });

    // loop cek adzan
    if(checkTimerId) clearInterval(checkTimerId);
    checkTimerId = setInterval(checkAdzanLoop, 5000);

    // auto refresh jadwal tiap 1 jam tanpa reload halaman
    if(scheduleRefreshTimerId) clearInterval(scheduleRefreshTimerId);
    scheduleRefreshTimerId = setInterval(function(){
      loadSholatBanyuwangi(function(ok){
        if(!ok){
          console.log('Gagal refresh jadwal adzan (auto), memakai jadwal terakhir.');
        } else {
          console.log('Jadwal adzan berhasil di-refresh otomatis.');
        }
      });
    }, 60 * 60 * 1000); // 1 jam

    // log server
    fetchLogs();
    if(logTimerId) clearInterval(logTimerId);
    logTimerId = setInterval(fetchLogs, 10000);
  }

  function stopEngine(){
    if(!started) return;
    started = false;

    if(btnStart) btnStart.classList.remove('hidden');
    if(btnStop)  btnStop.classList.add('hidden');
    if(statusBadge) statusBadge.textContent = 'Stopped';

    if(checkTimerId){ clearInterval(checkTimerId); checkTimerId = null; }
    if(countdownTimerId){ clearInterval(countdownTimerId); countdownTimerId = null; }
    if(logTimerId){ clearInterval(logTimerId); logTimerId = null; }
    if(scheduleRefreshTimerId){ clearInterval(scheduleRefreshTimerId); scheduleRefreshTimerId = null; }

    // pastikan audio test manual ikut berhenti saat engine di-stop
    stopAdzanTest();
    stopSubuhTest();
    stopDoaTest();
    stopAsingTest();
    stopUmbulTest();
    stopKahfiTest();

    nextRunTimestamp = null;
    if(countdownEl) countdownEl.textContent = '--:--:--';
    if(nextEventEl) nextEventEl.textContent = '-';
  }

  // ===== Event tombol =====
  if(btnStart){
    btnStart.addEventListener('click', function(){
      startEngine();
    });
  }
  if(btnStop){
    btnStop.addEventListener('click', function(){
      stopEngine();
    });
  }

  function updateBaseTestButtonUI(btn, idleText, stopText, isPlaying){
    if(!btn) return;
    if(isPlaying){
      btn.textContent = stopText;
      btn.classList.remove('border-slate-200', 'bg-slate-50', 'hover:bg-slate-100');
      btn.classList.add('border-rose-200', 'bg-rose-50', 'hover:bg-rose-100');
      return;
    }
    btn.textContent = idleText;
    btn.classList.remove('border-rose-200', 'bg-rose-50', 'hover:bg-rose-100');
    btn.classList.add('border-slate-200', 'bg-slate-50', 'hover:bg-slate-100');
  }

  function stopAdzanTest(){
    if(adzanTestAudio){
      try {
        adzanTestAudio.pause();
        adzanTestAudio.currentTime = 0;
      } catch(e) {}
      adzanTestAudio = null;
    }
    updateBaseTestButtonUI(btnTestAdzan, 'Tes adzan.mp3', 'Stop adzan.mp3', false);
  }

  function startAdzanTest(){
    if(adzanTestAudio) return;
    var audio = new Audio('adzan.mp3');
    audio.preload = 'auto';
    audio.onended = function(){
      adzanTestAudio = null;
      updateBaseTestButtonUI(btnTestAdzan, 'Tes adzan.mp3', 'Stop adzan.mp3', false);
    };
    audio.onerror = function(){
      adzanTestAudio = null;
      updateBaseTestButtonUI(btnTestAdzan, 'Tes adzan.mp3', 'Stop adzan.mp3', false);
      alert('Gagal memutar adzan.mp3 (cek file & lokasi).');
    };
    adzanTestAudio = audio;
    updateBaseTestButtonUI(btnTestAdzan, 'Tes adzan.mp3', 'Stop adzan.mp3', true);
    var p = audio.play();
    if(p && p.catch){
      p.catch(function(){
        stopAdzanTest();
        alert('Browser menolak autoplay. Klik halaman lalu ulangi tes.');
      });
    }
  }

  function stopSubuhTest(){
    if(subuhTestAudio){
      try {
        subuhTestAudio.pause();
        subuhTestAudio.currentTime = 0;
      } catch(e) {}
      subuhTestAudio = null;
    }
    updateBaseTestButtonUI(btnTestSubuh, 'Tes subuh.mp3', 'Stop subuh.mp3', false);
  }

  function startSubuhTest(){
    if(subuhTestAudio) return;
    var audio = new Audio('subuh.mp3');
    audio.preload = 'auto';
    audio.onended = function(){
      subuhTestAudio = null;
      updateBaseTestButtonUI(btnTestSubuh, 'Tes subuh.mp3', 'Stop subuh.mp3', false);
    };
    audio.onerror = function(){
      subuhTestAudio = null;
      updateBaseTestButtonUI(btnTestSubuh, 'Tes subuh.mp3', 'Stop subuh.mp3', false);
      alert('Gagal memutar subuh.mp3 (cek file & lokasi).');
    };
    subuhTestAudio = audio;
    updateBaseTestButtonUI(btnTestSubuh, 'Tes subuh.mp3', 'Stop subuh.mp3', true);
    var p = audio.play();
    if(p && p.catch){
      p.catch(function(){
        stopSubuhTest();
        alert('Browser menolak autoplay. Klik halaman lalu ulangi tes.');
      });
    }
  }

  function stopDoaTest(){
    if(doaTestAudio){
      try {
        doaTestAudio.pause();
        doaTestAudio.currentTime = 0;
      } catch(e) {}
      doaTestAudio = null;
    }
    updateBaseTestButtonUI(btnTestDoa, 'Tes sudah.mp3', 'Stop sudah.mp3', false);
  }

  function startDoaTest(){
    if(doaTestAudio) return;
    var audio = new Audio('sudah.mp3');
    audio.preload = 'auto';
    audio.onended = function(){
      doaTestAudio = null;
      updateBaseTestButtonUI(btnTestDoa, 'Tes sudah.mp3', 'Stop sudah.mp3', false);
    };
    audio.onerror = function(){
      doaTestAudio = null;
      updateBaseTestButtonUI(btnTestDoa, 'Tes sudah.mp3', 'Stop sudah.mp3', false);
      alert('Gagal memutar sudah.mp3 (cek file & lokasi).');
    };
    doaTestAudio = audio;
    updateBaseTestButtonUI(btnTestDoa, 'Tes sudah.mp3', 'Stop sudah.mp3', true);
    var p = audio.play();
    if(p && p.catch){
      p.catch(function(){
        stopDoaTest();
        alert('Browser menolak autoplay. Klik halaman lalu ulangi tes.');
      });
    }
  }

  if(btnTestAdzan){
    btnTestAdzan.addEventListener('click', function(){
      if(adzanTestAudio){
        stopAdzanTest();
        try {
          fetch(window.location.pathname + '?clientlog=1&msg=' + encodeURIComponent('STOP TEST adzan.mp3'));
        } catch(e) {}
        return;
      }
      startAdzanTest();
      try {
        fetch(window.location.pathname + '?clientlog=1&msg=' + encodeURIComponent('TEST adzan.mp3'));
      } catch(e) {}
    });
  }
  if(btnTestSubuh){
    btnTestSubuh.addEventListener('click', function(){
      if(subuhTestAudio){
        stopSubuhTest();
        try {
          fetch(window.location.pathname + '?clientlog=1&msg=' + encodeURIComponent('STOP TEST subuh.mp3'));
        } catch(e) {}
        return;
      }
      startSubuhTest();
      try {
        fetch(window.location.pathname + '?clientlog=1&msg=' + encodeURIComponent('TEST subuh.mp3'));
      } catch(e) {}
    });
  }
  if(btnTestDoa){
    btnTestDoa.addEventListener('click', function(){
      if(doaTestAudio){
        stopDoaTest();
        try {
          fetch(window.location.pathname + '?clientlog=1&msg=' + encodeURIComponent('STOP TEST sudah.mp3'));
        } catch(e) {}
        return;
      }
      startDoaTest();
      try {
        fetch(
          window.location.pathname +
          '?clientlog=1&msg=' +
          encodeURIComponent('TEST DOA (sudah.mp3) - tanpa adzan')
        );
      } catch(e) {}
    });
  }

  function updateUmbulTestUI(isPlaying){
    if(!btnTestUmbul) return;
    if(isPlaying){
      btnTestUmbul.textContent = 'Stop umbul.mp3';
      btnTestUmbul.classList.remove('border-emerald-200', 'bg-emerald-50', 'hover:bg-emerald-100');
      btnTestUmbul.classList.add('border-rose-200', 'bg-rose-50', 'hover:bg-rose-100');
      if(umbulTestStateEl) umbulTestStateEl.textContent = 'Playing (test mode)';
      return;
    }
    btnTestUmbul.textContent = 'Test umbul.mp3';
    btnTestUmbul.classList.remove('border-rose-200', 'bg-rose-50', 'hover:bg-rose-100');
    btnTestUmbul.classList.add('border-emerald-200', 'bg-emerald-50', 'hover:bg-emerald-100');
    if(umbulTestStateEl) umbulTestStateEl.textContent = 'Idle';
  }

  function updateAsingTestUI(isPlaying){
    if(!btnTestAsing) return;
    if(isPlaying){
      btnTestAsing.textContent = 'Stop asing.mp3';
      btnTestAsing.classList.remove('border-cyan-200', 'bg-cyan-50', 'hover:bg-cyan-100');
      btnTestAsing.classList.add('border-rose-200', 'bg-rose-50', 'hover:bg-rose-100');
      if(asingTestStateEl) asingTestStateEl.textContent = 'Playing (test mode)';
      return;
    }
    btnTestAsing.textContent = 'Test asing.mp3';
    btnTestAsing.classList.remove('border-rose-200', 'bg-rose-50', 'hover:bg-rose-100');
    btnTestAsing.classList.add('border-cyan-200', 'bg-cyan-50', 'hover:bg-cyan-100');
    if(asingTestStateEl) asingTestStateEl.textContent = 'Idle';
  }

  function updateKahfiTestUI(isPlaying){
    if(!btnTestKahfi) return;
    if(isPlaying){
      btnTestKahfi.textContent = 'Stop kahfi.mp3';
      btnTestKahfi.classList.remove('border-amber-200', 'bg-amber-50', 'hover:bg-amber-100');
      btnTestKahfi.classList.add('border-rose-200', 'bg-rose-50', 'hover:bg-rose-100');
      if(kahfiTestStateEl) kahfiTestStateEl.textContent = 'Playing (test mode)';
      return;
    }
    btnTestKahfi.textContent = 'Test kahfi.mp3';
    btnTestKahfi.classList.remove('border-rose-200', 'bg-rose-50', 'hover:bg-rose-100');
    btnTestKahfi.classList.add('border-amber-200', 'bg-amber-50', 'hover:bg-amber-100');
    if(kahfiTestStateEl) kahfiTestStateEl.textContent = 'Idle';
  }

  function stopUmbulTest(){
    if(umbulTestAudio){
      try {
        umbulTestAudio.pause();
        umbulTestAudio.currentTime = 0;
      } catch(e) {}
      umbulTestAudio = null;
    }
    updateUmbulTestUI(false);
  }

  function stopAsingTest(){
    if(asingTestAudio){
      try {
        asingTestAudio.pause();
        asingTestAudio.currentTime = 0;
      } catch(e) {}
      asingTestAudio = null;
    }
    updateAsingTestUI(false);
  }

  function startAsingTest(){
    if(asingTestAudio) return;
    var audio = new Audio('asing.mp3');
    audio.preload = 'auto';
    audio.onended = function(){
      asingTestAudio = null;
      updateAsingTestUI(false);
    };
    audio.onerror = function(){
      asingTestAudio = null;
      updateAsingTestUI(false);
      alert('Gagal memutar asing.mp3 (cek file & lokasi).');
    };
    asingTestAudio = audio;
    updateAsingTestUI(true);
    var p = audio.play();
    if(p && p.catch){
      p.catch(function(){
        stopAsingTest();
        alert('Browser menolak autoplay. Klik halaman lalu ulangi tes.');
      });
    }
  }

  function startUmbulTest(){
    if(umbulTestAudio) return;
    var audio = new Audio('umbul.mp3');
    audio.preload = 'auto';
    audio.onended = function(){
      umbulTestAudio = null;
      updateUmbulTestUI(false);
    };
    audio.onerror = function(){
      umbulTestAudio = null;
      updateUmbulTestUI(false);
      alert('Gagal memutar umbul.mp3 (cek file & lokasi).');
    };
    umbulTestAudio = audio;
    updateUmbulTestUI(true);
    var p = audio.play();
    if(p && p.catch){
      p.catch(function(){
        stopUmbulTest();
        alert('Browser menolak autoplay. Klik halaman lalu ulangi tes.');
      });
    }
  }

  function stopKahfiTest(){
    if(kahfiTestAudio){
      try {
        kahfiTestAudio.pause();
        kahfiTestAudio.currentTime = 0;
      } catch(e) {}
      kahfiTestAudio = null;
    }
    updateKahfiTestUI(false);
  }

  function startKahfiTest(){
    if(kahfiTestAudio) return;
    var audio = new Audio('kahfi.mp3');
    audio.preload = 'auto';
    audio.onended = function(){
      kahfiTestAudio = null;
      updateKahfiTestUI(false);
    };
    audio.onerror = function(){
      kahfiTestAudio = null;
      updateKahfiTestUI(false);
      alert('Gagal memutar kahfi.mp3 (cek file & lokasi).');
    };
    kahfiTestAudio = audio;
    updateKahfiTestUI(true);
    var p = audio.play();
    if(p && p.catch){
      p.catch(function(){
        stopKahfiTest();
        alert('Browser menolak autoplay. Klik halaman lalu ulangi tes.');
      });
    }
  }

  if(btnTestUmbul){
    btnTestUmbul.addEventListener('click', function(){
      if(umbulTestAudio){
        stopUmbulTest();
        try {
          fetch(window.location.pathname + '?clientlog=1&msg=' + encodeURIComponent('STOP TEST umbul.mp3'));
        } catch(e) {}
        return;
      }
      startUmbulTest();
      try {
        fetch(window.location.pathname + '?clientlog=1&msg=' + encodeURIComponent('TEST umbul.mp3'));
      } catch(e) {}
    });
  }
  if(btnTestAsing){
    btnTestAsing.addEventListener('click', function(){
      if(asingTestAudio){
        stopAsingTest();
        try {
          fetch(window.location.pathname + '?clientlog=1&msg=' + encodeURIComponent('STOP TEST asing.mp3'));
        } catch(e) {}
        return;
      }
      startAsingTest();
      try {
        fetch(window.location.pathname + '?clientlog=1&msg=' + encodeURIComponent('TEST asing.mp3'));
      } catch(e) {}
    });
  }
  if(btnTestKahfi){
    btnTestKahfi.addEventListener('click', function(){
      if(kahfiTestAudio){
        stopKahfiTest();
        try {
          fetch(window.location.pathname + '?clientlog=1&msg=' + encodeURIComponent('STOP TEST kahfi.mp3'));
        } catch(e) {}
        return;
      }
      startKahfiTest();
      try {
        fetch(window.location.pathname + '?clientlog=1&msg=' + encodeURIComponent('TEST kahfi.mp3'));
      } catch(e) {}
    });
  }

  // ===== INIT =====
  renderSholatListPlaceholder();
  if(modeInfo) modeInfo.textContent = 'Adzan + doa (mp3)';
  fetchLogs();
  updateBaseTestButtonUI(btnTestAdzan, 'Tes adzan.mp3', 'Stop adzan.mp3', false);
  updateBaseTestButtonUI(btnTestSubuh, 'Tes subuh.mp3', 'Stop subuh.mp3', false);
  updateBaseTestButtonUI(btnTestDoa, 'Tes sudah.mp3', 'Stop sudah.mp3', false);
  updateAsingTestUI(false);
  updateUmbulTestUI(false);
  updateKahfiTestUI(false);

  // Tidak auto-start: user harus klik Start agar izin audio jelas
})();
</script>
</body>
</html>
