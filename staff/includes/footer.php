<?php
/**
 * BRIGHTPATH — Shared Footer / JS utilities.
 * Provides: showToast(), openModal(), closeModal(), ajax(), profile dropdown, entrance animations.
 */
?>
<script>
/* ── TOAST ────────────────────────────────────────────── */
function showToast(message, type) {
    type = type || 'info';
    var icons = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        info:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    };
    var container = document.getElementById('toast-container');
    if (!container) return;
    var t = document.createElement('div');
    t.className = 'toast ' + type;
    t.innerHTML = (icons[type] || icons.info) + '<span>' + message + '</span>';
    container.appendChild(t);
    t.style.transition = 'opacity .3s ease';
    t.addEventListener('click', function(){ t.remove(); });
    setTimeout(function(){ t.style.opacity = '0'; }, 4200);
    setTimeout(function(){ t.remove(); }, 4500);
}

/* ── MODAL ────────────────────────────────────────────── */
function openModal(id)  { var el = document.getElementById(id); if(el) el.classList.add('open'); }
function closeModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('open');
    el.querySelectorAll('form').forEach(function(f){ f.reset(); });
}

/* Close on backdrop click */
document.addEventListener('click', function(e) {
    document.querySelectorAll('.modal.open').forEach(function(m) {
        if (e.target === m) closeModal(m.id);
    });
});

/* Close on Escape */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var open = document.querySelector('.modal.open');
        if (open) closeModal(open.id);
    }
});

/* ── AJAX HELPER ──────────────────────────────────────── */
function ajax(data, url) {
    url = url || window.location.href;
    var fd = new FormData();
    Object.entries(data).forEach(function(kv){ fd.append(kv[0], kv[1]); });
    return fetch(url, {
        method:  'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body:    fd
    }).then(function(r){ return r.json(); });
}

/* ── PROFILE DROPDOWN ─────────────────────────────────── */
(function(){
    var wrap = document.getElementById('profileWrap');
    if (!wrap) return;
    wrap.querySelector('.user-pill').addEventListener('click', function(e){
        e.stopPropagation();
        wrap.classList.toggle('open');
    });
    document.addEventListener('click', function(e){
        if (wrap && !wrap.contains(e.target)) wrap.classList.remove('open');
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && wrap) wrap.classList.remove('open');
    });
})();

/* ── ENTRANCE ANIMATIONS ──────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    /* Stat cards */
    document.querySelectorAll('.stat-card').forEach(function(c, i) {
        c.style.opacity = '0';
        c.style.transform = 'translateY(16px)';
        c.style.transition = 'opacity .4s ease, transform .4s ease, box-shadow .2s';
        setTimeout(function(){ c.style.opacity = '1'; c.style.transform = ''; }, 60 + i * 50);
    });
    /* Function cards */
    document.querySelectorAll('.fn-card').forEach(function(c, i) {
        c.style.opacity = '0';
        c.style.transform = 'translateY(20px)';
        c.style.transition = 'opacity .45s ease, transform .45s ease, box-shadow .22s, border-color .22s';
        setTimeout(function(){ c.style.opacity = '1'; c.style.transform = ''; }, 80 + i * 60);
    });
    /* Quick action buttons feedback */
    document.querySelectorAll('.qa-btn').forEach(function(btn) {
        btn.addEventListener('click', function(){
            this.style.transform = 'scale(.97)';
            var self = this;
            setTimeout(function(){ self.style.transform = ''; }, 150);
        });
    });
});
</script>
</body>
</html>
