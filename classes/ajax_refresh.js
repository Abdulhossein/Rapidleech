var idleTime = 0;
$(document).ready(function(){
  var idleInterval = setInterval(function(){ idleTime++; }, 1000);
  $(this).mousemove(function(e){
    var tmp = idleTime;
    idleTime = 0;
    if (tmp >= 120) { stats_timer = setTimeout(refreshStats, 1000); }
  });
});

var stats_timed = 10;
function refreshStats() {
	$.ajax({
		type: "GET",
		url: 'ajax.php?ajax=server_stats',
		dataType: 'json',
		success: function (data) {
			$('#cpuload').html(data.CPULoad);
			$('#inuse').html(data.InUse);
			$('#inusepercent').html(data.InUsePercent);
			$('#freespace').html(data.FreeSpace);
			$('#diskspace').html(data.DiskSpace);
			diskPercent = new Image();
			diskPercent.src = "classes/bar.php?rating=" + data.DiskPercent;
			$('#diskpercent').attr('src',diskPercent.src);
			if ($('#cpubarfill').length) {
				var pct = parseFloat(data.CPUPercent) || 0;
				$('#cpubarfill').css('width', Math.max(0, Math.min(100, pct)) + '%');
				$('#cpubarfill').removeClass('fl-cpu-ok fl-cpu-warn fl-cpu-high')
					.addClass(pct >= 85 ? 'fl-cpu-high' : (pct >= 60 ? 'fl-cpu-warn' : 'fl-cpu-ok'));
			} else {
				cpuPercent = new Image();
				cpuPercent.src = "classes/bar.php?rating=" + data.CPUPercent;
				$('#cpupercent').attr('src',cpuPercent.src);
			}
			if (data.CPUHistory && $('#cpuSparkLine').length) { flDrawCpuSpark(data.CPUHistory); }
			if (stats_timed < 60) { stats_timed = stats_timed + 10; }
			if (idleTime < 120) {
				clearTimeout(stats_timer);
				stats_timer = setTimeout(refreshStats, stats_timed * 1000);
			}
		}
	});
}

function flDrawCpuSpark(history) {
	var line = document.getElementById('cpuSparkLine');
	var area = document.getElementById('cpuSparkArea');
	if (!line || !area || !history || !history.length) return;
	var W = 100, H = 36, n = history.length;
	var pts = history.map(function (h, i) {
		var x = n > 1 ? (i / (n - 1)) * W : W / 2;
		var y = H - 2 - (Math.max(0, Math.min(100, parseFloat(h[0]) || 0)) / 100) * (H - 4);
		return [x, y];
	});
	var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(2) + ' ' + p[1].toFixed(2); }).join(' ');
	line.setAttribute('d', d);
	area.setAttribute('d', d + ' L' + W + ' ' + H + ' L0 ' + H + ' Z');
	var vals = history.map(function (h) { return parseFloat(h[0]) || 0; });
	var now = vals[vals.length - 1];
	$('#cpuSparkNow').text('now ' + Math.round(now) + '%');
	$('#cpuSparkMin').text('min ' + Math.round(Math.min.apply(null, vals)) + '%');
	$('#cpuSparkMax').text('max ' + Math.round(Math.max.apply(null, vals)) + '%');
}

/* ============================================================
   Live download stream (SSE) with automatic polling fallback.
   Renders per-download progress bars into #pendingList if present.
   ============================================================ */
var flLive = { source: null, pollTimer: null, lastKey: '', mode: '' };

/* Per-download speed history, keyed by filename: {hist: [[t,bytes],...], lastBytes, lastT, speed} */
var flDlSpeed = {};

function flTrackSpeed(d) {
	var key = String(d.filename || '');
	if (!key) return 0;
	var now = Date.now();
	var bytes = parseInt(d.bytes, 10) || 0;
	var st = flDlSpeed[key];
	if (!st || st.lastBytes > bytes) { st = flDlSpeed[key] = { hist: [], lastBytes: bytes, lastT: now, speed: 0 }; }
	if (bytes !== st.lastBytes && now - st.lastT >= 500) {
		var dt = (now - st.lastT) / 1000;
		var inst = Math.max(0, (bytes - st.lastBytes) / dt);
		st.speed = st.speed ? (st.speed * 0.6 + inst * 0.4) : inst;
		st.lastBytes = bytes;
		st.lastT = now;
		st.hist.push([now, st.speed]);
		if (st.hist.length > 40) st.hist.shift();
	}
	return st.speed;
}

function flFmtSpeed(bps) {
	if (!bps || bps <= 0) return '—';
	var units = ['B/s', 'KB/s', 'MB/s', 'GB/s'], u = 0, v = bps;
	while (v >= 1024 && u < units.length - 1) { v /= 1024; u++; }
	return (v >= 100 ? Math.round(v) : v.toFixed(1)) + ' ' + units[u];
}

function flFmtEta(sec) {
	if (!isFinite(sec) || sec <= 0 || sec > 86400 * 7) return '—';
	if (sec < 60) return Math.ceil(sec) + 's';
	if (sec < 3600) return Math.floor(sec / 60) + 'm ' + Math.round(sec % 60) + 's';
	var h = Math.floor(sec / 3600);
	return h + 'h ' + Math.round((sec % 3600) / 60) + 'm';
}

function flDrawSpeedSpark(svgId, hist) {
	var svg = document.getElementById(svgId);
	if (!svg) return;
	var line = svg.querySelector('.fl-dl-spark-line');
	var area = svg.querySelector('.fl-dl-spark-area');
	if (!line || !area) return;
	var W = 100, H = 30;
	if (!hist || hist.length < 2) { line.setAttribute('d', ''); area.setAttribute('d', ''); return; }
	var max = Math.max.apply(null, hist.map(function (h) { return h[1]; })) || 1;
	var n = hist.length;
	var pts = hist.map(function (h, i) {
		var x = (i / (n - 1)) * W;
		var y = H - 2 - (Math.max(0, h[1]) / max) * (H - 4);
		return [x, y];
	});
	var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(2) + ' ' + p[1].toFixed(2); }).join(' ');
	line.setAttribute('d', d);
	area.setAttribute('d', d + ' L' + W + ' ' + H + ' L0 ' + H + ' Z');
}

function flRenderChunkBar(d) {
	if (!d.chunks || !d.chunks.length) return '';
	var expected = parseInt(d.chunkBytes, 10) || 0;
	var segs = '';
	for (var i = 0; i < d.chunks.length; i++) {
		var pct = expected > 0 ? Math.max(0, Math.min(100, (d.chunks[i] / expected) * 100)) : 0;
		segs += '<div class="fl-chunkseg" title="Chunk ' + (i + 1) + ': ' + Math.round(pct) + '%"><div class="fl-chunkseg-fill" style="width:' + pct + '%;"></div></div>';
	}
	return '<div class="fl-chunkbar" title="' + d.chunks.length + ' parallel chunks">' + segs + '</div>';
}

function flRenderPending(downloads) {
	if (!downloads) return;
	flPruneSpeed(downloads);
	// Flavor template registers its own table renderer — feed it the live data
	if (typeof window.flApplyPendingDownloads === 'function') { window.flApplyPendingDownloads(downloads); return; }
	var list = document.getElementById('pendingList');
	if (!list) return;
	var key = JSON.stringify(downloads);
	if (key === flLive.lastKey) return;
	flLive.lastKey = key;
	var html = '';
	for (var i = 0; i < downloads.length; i++) {
		var d = downloads[i];
		var pct = Math.max(0, Math.min(100, parseFloat(d.progress) || 0));
		var merging = /merging/i.test(d.status || '');
		var barColor = merging ? 'linear-gradient(90deg,#f59e0b,#d97706)' : 'linear-gradient(90deg,#6366f1,#a855f7)';
		html += '<div class="fl-pending-item" style="margin-bottom:10px;">'
			+ '<div style="display:flex;justify-content:space-between;gap:8px;font-size:12px;margin-bottom:4px;">'
			+ '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + String(d.filename).replace(/"/g, '&quot;') + '">' + String(d.filename).replace(/</g, '&lt;') + '</span>'
			+ '<span style="flex-shrink:0;opacity:.7;">' + String(d.status || '') + '</span>'
			+ '</div>'
			+ '<div style="height:6px;border-radius:3px;background:var(--fl-border,#333);overflow:hidden;">'
			+ '<div style="height:100%;width:' + pct + '%;border-radius:3px;background:' + barColor + ';transition:width .6s ease;"></div>'
			+ '</div>'
			+ '<div style="font-size:11px;opacity:.5;margin-top:2px;">' + String(d.size || '') + '</div>'
			+ '</div>';
	}
	list.innerHTML = html;
	list.style.display = downloads.length ? '' : 'none';
}

function flPollPending() {
	$.ajax({
		type: 'GET',
		url: 'ajax.php?ajax=pending_downloads',
		dataType: 'json',
		success: function (data) { flRenderPending(data && data.downloads); }
	});
}

/* Prune speed history for downloads no longer present in the live data */
function flPruneSpeed(downloads) {
	var seen = {};
	for (var i = 0; i < downloads.length; i++) seen[String(downloads[i].filename || '')] = 1;
	for (var k in flDlSpeed) { if (!seen[k]) delete flDlSpeed[k]; }
}

function flStartLiveStream() {
	if (flLive.mode === 'sse' || flLive.mode === 'poll') return;
	if (!window.EventSource) { flLive.mode = 'poll'; flPollPending(); flLive.pollTimer = setInterval(flPollPending, 3000); return; }
	try {
		var es = new EventSource('ajax.php?ajax=live_stream');
		flLive.source = es;
		es.addEventListener('progress', function (e) {
			if (flLive.mode !== 'sse') { flLive.mode = 'sse'; if (flLive.pollTimer) { clearInterval(flLive.pollTimer); flLive.pollTimer = null; } }
			try { flRenderPending(JSON.parse(e.data).downloads); } catch (err) { /* ignore malformed frame */ }
		});
		es.onerror = function () {
			// SSE failed (proxy buffering, short-lived host limits) — fall back to polling silently
			try { es.close(); } catch (e2) { /* noop */ }
			if (flLive.mode !== 'poll') {
				flLive.mode = 'poll';
				flPollPending();
				flLive.pollTimer = setInterval(flPollPending, 3000);
			}
		};
	} catch (e3) {		flLive.mode = 'poll';
		flPollPending();
		flLive.pollTimer = setInterval(flPollPending, 3000);
	}
}

$(document).ready(function () { flStartLiveStream(); });
