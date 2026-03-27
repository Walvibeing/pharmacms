<?php
require_once __DIR__ . '/../config.php';
$screenKey = $_GET['key'] ?? '';
if (empty($screenKey)) {
    echo '<!DOCTYPE html><html><body style="background:#000;color:#555;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0"><p>No screen key provided. Add ?key=YOUR_SCREEN_KEY to the URL.</p></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?= APP_NAME ?> Player</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/player.css">
</head>
<body>
<div id="player">
    <div class="loading" id="loadingScreen">
        <div class="loading-spinner"></div>
        <span>Connecting...</span>
    </div>
</div>
<div class="connection-status connected" id="connectionDot"></div>

<!-- Pairing Screen -->
<div id="pairingScreen" style="display:none;position:fixed;inset:0;background:#0a0a0a;z-index:1000;align-items:center;justify-content:center;font-family:'Inter',sans-serif">
    <div style="text-align:center;color:#fff;max-width:600px;padding:40px">
        <div style="margin-bottom:32px">
            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#0073ea" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                <line x1="8" y1="21" x2="16" y2="21"></line>
                <line x1="12" y1="17" x2="12" y2="21"></line>
            </svg>
        </div>
        <div id="pairingScreenName" style="font-size:1.1rem;color:#888;margin-bottom:12px"></div>
        <div id="pairingCode" style="font-size:6rem;font-weight:700;letter-spacing:0.3em;color:#fff;margin:24px 0;line-height:1;font-variant-numeric:tabular-nums">------</div>
        <p style="font-size:1.1rem;color:#999;margin:0 0 32px 0;line-height:1.5">Enter this code in <strong style="color:#ccc"><?= APP_NAME ?></strong> to connect this screen</p>
        <div id="pairingStatus" style="font-size:0.85rem;color:#555">
            <div class="loading-spinner" style="width:20px;height:20px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:8px"></div>
            Waiting for pairing...
        </div>
        <div id="pairingExpiry" style="font-size:0.75rem;color:#444;margin-top:16px"></div>
    </div>
</div>

<script>
(function() {
    var BASE_URL = '<?= BASE_URL ?>';
    var SCREEN_KEY = '<?= htmlspecialchars($screenKey, ENT_QUOTES) ?>';
    var CONTENT_URL = BASE_URL + 'api/screen_content.php?key=' + SCREEN_KEY;
    var PING_URL = BASE_URL + 'api/screen_ping.php?key=' + SCREEN_KEY;
    var PAIR_URL = BASE_URL + 'api/screen_pair.php';

    var player = document.getElementById('player');
    var loadingScreen = document.getElementById('loadingScreen');
    var connectionDot = document.getElementById('connectionDot');
    var pairingScreen = document.getElementById('pairingScreen');
    var pairingCodeEl = document.getElementById('pairingCode');
    var pairingScreenNameEl = document.getElementById('pairingScreenName');
    var pairingExpiryEl = document.getElementById('pairingExpiry');

    var currentData = null;
    var currentIndex = 0;
    var slideTimer = null;
    var slides = [];
    var isPaired = false;
    var pairPollTimer = null;
    var pairCodeExpiry = null;

    // Fetch content
    function fetchContent() {
        fetch(CONTENT_URL)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                connectionDot.className = 'connection-status connected';
                handleContent(data);
            })
            .catch(function() {
                connectionDot.className = 'connection-status disconnected';
            });
    }

    // Ping server
    function ping() {
        fetch(PING_URL).catch(function() {});
    }

    // Handle content response
    function handleContent(data) {
        // Check if content actually changed
        var dataStr = JSON.stringify(data);
        if (currentData === dataStr) return;
        currentData = dataStr;

        // Clear existing
        clearTimeout(slideTimer);
        currentIndex = 0;
        slides = [];

        // Remove old slides
        var oldSlides = player.querySelectorAll('.slide, .emergency-bar, .blank-screen');
        oldSlides.forEach(function(el) { el.remove(); });

        if (loadingScreen) {
            loadingScreen.style.display = 'none';
        }

        if (data.mode === 'emergency') {
            renderEmergency(data.emergency);
        } else if (data.mode === 'blank' || !data.items || data.items.length === 0) {
            renderBlank();
        } else if (data.mode === 'single' && data.items.length === 1) {
            renderSingle(data.items[0]);
        } else {
            renderPlaylist(data.items);
        }
    }

    // Emergency mode
    function renderEmergency(emergency) {
        var bar = document.createElement('div');
        bar.className = 'emergency-bar';
        bar.textContent = 'EMERGENCY: ' + emergency.title;
        player.appendChild(bar);

        var slide = createSlide(emergency.media_url, emergency.type, true);
        player.appendChild(slide);
        setTimeout(function() { slide.classList.add('active'); }, 50);
    }

    // Blank screen
    function renderBlank() {
        var blank = document.createElement('div');
        blank.className = 'blank-screen';
        blank.innerHTML = '<span style="opacity:0.2">No content assigned</span>';
        player.appendChild(blank);
    }

    // Single item
    function renderSingle(item) {
        var slide = createSlide(item.url, item.type, true);
        player.appendChild(slide);
        setTimeout(function() { slide.classList.add('active'); }, 50);
    }

    // Playlist mode
    function renderPlaylist(items) {
        slides = items;
        if (slides.length === 0) { renderBlank(); return; }

        // Preload first two items
        showSlide(0);
    }

    function showSlide(index) {
        if (slides.length === 0) return;
        currentIndex = index % slides.length;

        var item = slides[currentIndex];

        // Remove non-active slides
        var existing = player.querySelectorAll('.slide');
        existing.forEach(function(el) {
            if (!el.classList.contains('active')) el.remove();
        });

        var slide = createSlide(item.url, item.type, false);
        player.appendChild(slide);

        // Fade in new
        setTimeout(function() {
            slide.classList.add('active');
            // Fade out old
            existing.forEach(function(el) {
                el.classList.remove('active');
                setTimeout(function() { el.remove(); }, 1100);
            });
        }, 50);

        // Schedule next
        var duration = (item.duration || 10) * 1000;

        if (item.type === 'video') {
            var vid = slide.querySelector('video');
            if (vid) {
                vid.onended = function() {
                    if (slides.length > 1) {
                        showSlide(currentIndex + 1);
                    }
                    // If single video in playlist, it loops via attribute
                };
            }
            // For single video, let it loop; for playlist, advance on end
            if (slides.length > 1) return;
        }

        slideTimer = setTimeout(function() {
            showSlide(currentIndex + 1);
        }, duration);
    }

    // Create a slide element
    function createSlide(url, type, loop) {
        var slide = document.createElement('div');
        slide.className = 'slide';

        if (type === 'video') {
            var video = document.createElement('video');
            video.src = url;
            video.autoplay = true;
            video.muted = true;
            video.playsInline = true;
            if (loop) video.loop = true;
            video.play().catch(function() {});
            slide.appendChild(video);
        } else {
            var img = document.createElement('img');
            img.src = url;
            img.alt = '';
            slide.appendChild(img);
        }

        return slide;
    }

    // ---- Pairing Flow ----

    // Request a pairing code from the server
    function requestPairCode() {
        fetch(PAIR_URL + '?action=request_code&key=' + SCREEN_KEY)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.code) {
                    // Format code with a space in the middle for readability
                    var code = data.code;
                    pairingCodeEl.textContent = code.substring(0, 3) + ' ' + code.substring(3);
                    pairCodeExpiry = new Date(data.expires_at).getTime();
                    if (data.screen_name) {
                        pairingScreenNameEl.textContent = data.screen_name;
                    }
                    updateExpiryCountdown();
                }
            })
            .catch(function() {
                pairingCodeEl.textContent = '------';
            });
    }

    // Update the expiry countdown display
    function updateExpiryCountdown() {
        if (!pairCodeExpiry) return;
        var now = Date.now();
        var remaining = Math.max(0, Math.floor((pairCodeExpiry - now) / 1000));

        if (remaining <= 0) {
            pairingExpiryEl.textContent = 'Code expired. Requesting new code...';
            requestPairCode();
            return;
        }

        var mins = Math.floor(remaining / 60);
        var secs = remaining % 60;
        pairingExpiryEl.textContent = 'Code expires in ' + mins + ':' + (secs < 10 ? '0' : '') + secs;
    }

    // Poll the server to check if pairing completed
    function checkPaired() {
        fetch(PAIR_URL + '?action=check_paired&key=' + SCREEN_KEY)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.paired) {
                    onPaired();
                }
            })
            .catch(function() {});
    }

    // Called when pairing is confirmed
    function onPaired() {
        isPaired = true;
        clearInterval(pairPollTimer);
        pairingScreen.style.display = 'none';
        startContentFlow();
    }

    // Show the pairing screen
    function showPairingScreen() {
        pairingScreen.style.display = 'flex';
        if (loadingScreen) loadingScreen.style.display = 'none';
        requestPairCode();

        // Poll for pairing status every 5 seconds
        pairPollTimer = setInterval(checkPaired, 5000);

        // Update expiry countdown every second
        setInterval(updateExpiryCountdown, 1000);
    }

    // Start normal content polling
    function startContentFlow() {
        fetchContent();
        ping();
        setInterval(fetchContent, 30000);
        setInterval(ping, 60000);
    }

    // ---- Initial Boot: Check pairing status ----
    fetch(PAIR_URL + '?action=check_paired&key=' + SCREEN_KEY)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.paired) {
                // Already paired, go straight to content
                isPaired = true;
                pairingScreen.style.display = 'none';
                startContentFlow();
            } else {
                // Not paired, show pairing screen
                showPairingScreen();
            }
        })
        .catch(function() {
            // If pairing check fails (e.g. table not created yet), fall back to content mode
            pairingScreen.style.display = 'none';
            startContentFlow();
        });

    // Prevent screen sleep (attempt)
    if ('wakeLock' in navigator) {
        navigator.wakeLock.request('screen').catch(function() {});
    }

    // Re-request wake lock on visibility change
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible' && 'wakeLock' in navigator) {
            navigator.wakeLock.request('screen').catch(function() {});
        }
    });
})();
</script>
</body>
</html>
