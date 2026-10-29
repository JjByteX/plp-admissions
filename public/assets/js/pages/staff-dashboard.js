(function(){
    var POLL_INTERVAL = 30000;
    var statsArea = document.querySelector('.stat-grid');
    var pipelineArea = document.querySelector('.dashboard-grid');
    if (!statsArea && !pipelineArea) return;

    function pollDashboard() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            if (xhr.status === 200) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(xhr.responseText, 'text/html');
                var newStats = doc.querySelector('.stat-grid');
                var newPipeline = doc.querySelector('.dashboard-grid');
                if (newStats && statsArea) {
                    statsArea.innerHTML = newStats.innerHTML;
                }
                if (newPipeline && pipelineArea) {
                    pipelineArea.innerHTML = newPipeline.innerHTML;
                }
            }
        };
        xhr.send();
    }

    setInterval(pollDashboard, POLL_INTERVAL);
})();
