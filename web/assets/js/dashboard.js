/* Page script of templates/views/dashboard/index.php */

(function () {
    const labels = window.DASHBOARD_PAGE.labels;
    function esc(s) { const d = document.createElement("div"); d.textContent = s; return d.innerHTML; }
    function render(data) {
        document.querySelectorAll("#dashLive [data-live], [data-live]").forEach(function (el) {
            const k = el.dataset.live; if (k in data) el.textContent = data[k];
        });
        const box = document.getElementById("dashTrunkUsage");
        if (!box) return;
        if (!data.trunk_usage.length) { box.innerHTML = "<span class=\"u-muted u-fs-12\">" + esc(labels.none) + "</span>"; return; }
        box.innerHTML = data.trunk_usage.map(function (t) {
            const pct = t.max > 0 ? Math.min(100, Math.round(t.in_use * 100 / t.max)) : (t.in_use > 0 ? 100 : 0);
            const txt = t.in_use + (t.max > 0 ? " / " + t.max : "") + (t.in_use === 0 ? " · " + labels.free : "");
            return "<div class=\"dash-trunk-row\"><span class=\"dash-trunk-name\">" + esc(t.title) + "</span>"
                + "<span class=\"dash-trunk-bar\"><span style=\"width:" + pct + "%\"></span></span>"
                + "<span class=\"dash-trunk-count\">" + esc(txt) + "</span></div>";
        }).join("");
    }
    render(window.DASHBOARD_PAGE.live);
    const timer = setInterval(function () {
        if (!document.getElementById("dashLive")) { clearInterval(timer); return; }
        if (document.hidden) return;
        fetch("/api/dashboard_live.php", { cache: "no-store" }).then(function (r) { return r.json(); })
            .then(function (d) { if (d.success) render(d); }).catch(function () {});
    }, 5000);
})();
