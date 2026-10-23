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
