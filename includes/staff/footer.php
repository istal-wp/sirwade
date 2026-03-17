
<!-- ── STAFF FOOTER ──────────────────────────────────────────────── -->
<script>
// Modal helpers (available to all staff pages)
function openModal(id) {
    var m = document.getElementById(id);
    if (m) m.classList.add('open');
}
function closeModal(id) {
    var m = document.getElementById(id);
    if (m) m.classList.remove('open');
}
// Close modal on backdrop click
document.addEventListener('click', function (e) {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('open');
    }
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.open').forEach(function (m) {
            m.classList.remove('open');
        });
    }
});
</script>
</body>
</html>
