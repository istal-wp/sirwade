
<!-- ── ADMIN FOOTER ──────────────────────────────────────────────── -->
<script>
// Profile pill dropdown toggle (shared across all admin pages)
(function () {
    var w = document.getElementById('profileWrap');
    if (!w) return;
    w.querySelector('.user-pill').addEventListener('click', function (e) {
        e.stopPropagation();
        w.classList.toggle('open');
    });
    document.addEventListener('click', function (e) {
        if (w && !w.contains(e.target)) w.classList.remove('open');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && w) w.classList.remove('open');
    });
})();

// Auto-refresh every 5 minutes
setTimeout(function () { location.reload(); }, 300000);
</script>
</body>
</html>
