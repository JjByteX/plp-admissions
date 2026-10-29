(function () {

    var DATA = window.PAGE.data;

    function v(n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }

    var charts = {};

    function buildCharts() {
        Object.values(charts).forEach(function(c) { if (c) c.destroy(); });
        charts = {};

        var radius      = parseInt(v('--radius'), 10) || 8;   // shared radius token
        var accent      = v('--accent');
        var accentLight = v('--accent-light');
        var textPrimary = v('--text-primary');
        var textSecond  = v('--text-secondary');
        var border      = v('--border');
        var radius      = parseFloat(v('--radius')) || 8;
        // Single accent color family for all charts — no rainbow
        var barColor    = accentLight || accent;

        Chart.defaults.font  = { family: v('--font-sans') || 'DM Sans,sans-serif', size: 14 };
        Chart.defaults.color = textSecond;

        // Print the count at the end of every bar so nobody has to hover to read it.
        var valueLabels = { id: 'valueLabels', afterDatasetsDraw: function (chart) {
            var ctx = chart.ctx;
            ctx.save();
            ctx.fillStyle    = textPrimary;
            ctx.font         = '600 14px ' + (v('--font-sans') || 'DM Sans,sans-serif');
            ctx.textBaseline = 'middle';
            ctx.textAlign    = 'left';
            chart.getDatasetMeta(0).data.forEach(function (bar, i) {
                if (!chart.data.datasets[0].data[i]) return; // hide 0 labels
                ctx.fillText(String(chart.data.datasets[0].data[i]), bar.x + 8, bar.y);
            });
            ctx.restore();
        } };
        function mk(id, cfg) {
            var el = document.getElementById(id);
            if (!el) return null;
            cfg.plugins = [valueLabels];
            cfg.options.layout = { padding: { right: 32 } };
            return new Chart(el, cfg);
        }

        var xGrid = { grid:{color:border}, border:{display:false}, ticks:{color:textSecond, precision:0} };
        var yFlat  = { grid:{display:false}, border:{display:false}, ticks:{color:textPrimary, font:{size:14}} };
        var tip   = { titleFont:{size:14}, bodyFont:{size:14}, callbacks:{ label:function(c){ return ' '+c.raw+' applicants'; } } };
        function fullTip(key){
            return { titleFont:{size:14}, bodyFont:{size:14}, callbacks:{
                title:function(items){ return DATA[key].full[items[0].dataIndex]; },
                label:function(c){ return ' '+c.raw+' applicants'; } } };
        }

        // Pipeline — horizontal bar (same accent, different opacity per stage)
        charts.pipeline = mk('chartPipeline', {
            type: 'bar',
            data: {
                labels: DATA.pipeline.labels,
                datasets: [{ data: DATA.pipeline.data, borderRadius:radius, borderSkipped:false,
                    backgroundColor: DATA.pipeline.data.map(function(){ return barColor; }) }]
            },
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{display:false}, tooltip:tip },
                scales:{ x:xGrid, y:yFlat } }
        });

        // Course — horizontal bar
        charts.course = mk('chartCourse', {
            type: 'bar',
            data: { labels:DATA.course.labels,
                datasets:[{ data:DATA.course.data, backgroundColor:barColor, borderRadius:radius, borderSkipped:false, barThickness:14, maxBarThickness:16 }] },
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{display:false}, tooltip:fullTip('course') },
                scales:{ x:xGrid, y:yFlat } }
        });

        // SHS Strand — same single color
        charts.strand = mk('chartStrand', {
            type: 'bar',
            data: { labels:DATA.strand.labels,
                datasets:[{ data:DATA.strand.data, backgroundColor:barColor, borderRadius:radius, borderSkipped:false }] },
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{display:false}, tooltip:fullTip('strand') },
                scales:{ x:xGrid, y:yFlat } }
        });
        var sl=document.getElementById('strandLegend');
        if(sl) sl.innerHTML='';

    }

    buildCharts();

    new MutationObserver(function(ms){
        ms.forEach(function(m){ if(m.attributeName==='data-theme') buildCharts(); });
    }).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});

    // Date picker
    var BASE = window.PAGE.baseUrl;
    var SY = window.PAGE.sy;
    var SYQ=SY ? '&sy='+SY : '';
    window.dpToggle=function(e){ e.stopPropagation(); document.getElementById('dpMenu').classList.toggle('open'); };
    window.dpSet   =function(r){ window.location.href=BASE+'?range='+r+SYQ; };
    window.dpCustom=function() { document.getElementById('dpCustomRow').classList.add('show'); };
    window.dpApply =function() {
        var f=document.getElementById('dpFrom').value, t=document.getElementById('dpTo').value;
        if(f&&t) window.location.href=BASE+'?range=custom&from='+encodeURIComponent(f)+'&to='+encodeURIComponent(t)+SYQ;
    };
    document.addEventListener('click',function(e){
        var w=document.getElementById('dpWrap');
        if(w&&!w.contains(e.target)) document.getElementById('dpMenu').classList.remove('open');
    });

})();
