/*
  Document      : project.progress.m.js
  Description   : Project Progress untuk HP (Android/iPhone). TANPA ExtJS (vanilla JS + PWA).
                  - Progress per proyek (persen 0-100) + catatan + foto + GPS opsional
                  - Foto dikompres di HP (canvas) sebelum dikirim
                  - OFFLINE: laporan disimpan di IndexedDB (antrean), dikirim otomatis saat online / aplikasi dibuka.
                    Setiap laporan punya client_uuid -> kiriman ulang tidak membuat data ganda (dijaga server).
  Backend       : /proyek/php-crud/projectprogressCRD.php
*/
(function () {
    'use strict';

    var CRD = '/proyek/php-crud/projectprogressCRD.php';
    var ACCESS_ADD = 2;                       // samakan dengan PRG_ACCESS_ADD di backend
    var MAX_PHOTOS = 5, MAX_SIDE = 1280, JPEG_Q = 0.75;
    var LS_PROJECTS = 'prg_projects_cache';

    var cur = null;            // proyek terpilih {id,name,progress}
    var photos = [];           // [{blob, url}] foto untuk laporan yang sedang diisi
    var geo = null;            // {lat, lng}
    var syncing = false;

    function $(id) { return document.getElementById(id); }
    function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : v; return d.innerHTML; }
    function todayStr() { var d = new Date(), m = d.getMonth() + 1, dd = d.getDate(); return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dd < 10 ? '0' + dd : dd); }
    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16);
        });
    }
    function showMsg(t, type) {
        var m = $('msg'); m.textContent = t; m.className = 'msg ' + (type || ''); 
        if (type === 'ok') setTimeout(function () { m.className = 'msg hidden'; }, 4000);
    }
    function hideMsg() { $('msg').className = 'msg hidden'; }

    // ------------------------------------------------------------
    // 0. Jaringan
    // ------------------------------------------------------------
    // GET/POST JSON; error jaringan -> reject {offline:true}; 401 -> reject {auth:true}
    function api(params, formData) {
        var opt = { credentials: 'same-origin' }, url = CRD;
        if (formData) { opt.method = 'POST'; opt.body = formData; }
        else { url += '?' + Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&'); }
        return fetch(url, opt).then(function (r) {
            if (r.status === 401) return Promise.reject({ auth: true });
            return r.json().catch(function () { return Promise.reject({ message: 'Respons server tidak valid.' }); });
        }, function () { return Promise.reject({ offline: true }); });
    }
    function updateNet() {
        var p = $('pillNet'); p.textContent = navigator.onLine ? 'online' : 'offline'; p.className = 'pill' + (navigator.onLine ? '' : ' off');
    }

    // ------------------------------------------------------------
    // 1. IndexedDB (antrean laporan offline)
    // ------------------------------------------------------------
    var dbp = null;
    function openDb() {
        if (dbp) return dbp;
        dbp = new Promise(function (res, rej) {
            var rq = indexedDB.open('prgQueue', 1);
            rq.onupgradeneeded = function () { rq.result.createObjectStore('queue', { keyPath: 'uuid' }); };
            rq.onsuccess = function () { res(rq.result); };
            rq.onerror = function () { rej(rq.error); };
        });
        return dbp;
    }
    function tx(mode, fn) {
        return openDb().then(function (db) {
            return new Promise(function (res, rej) {
                var t = db.transaction('queue', mode), s = t.objectStore('queue'), out = fn(s);
                t.oncomplete = function () { res(out && out.result !== undefined ? out.result : undefined); };
                t.onerror = function () { rej(t.error); };
            });
        });
    }
    function qPut(item) { return tx('readwrite', function (s) { return s.put(item); }); }
    function qDel(id) { return tx('readwrite', function (s) { return s.delete(id); }); }
    function qAll() { return tx('readonly', function (s) { return s.getAll(); }).then(function (r) { return r || []; }); }

    // ------------------------------------------------------------
    // 2. Daftar proyek (cache localStorage agar tetap tampil saat offline)
    // ------------------------------------------------------------
    function loadProjects() {
        var cached = null;
        try { cached = JSON.parse(localStorage.getItem(LS_PROJECTS) || 'null'); } catch (e) {}
        if (cached) renderProjects(cached);
        api({ task: 'list_projects' }).then(function (res) {
            if (!res.success) { showMsg(res.message || 'Gagal memuat proyek.', 'err'); return; }
            try { localStorage.setItem(LS_PROJECTS, JSON.stringify(res.data)); } catch (e) {}
            renderProjects(res.data);
        }, function (e) {
            if (e.auth) showMsg('Sesi berakhir. Buka ulang halaman & login.', 'err');
            else if (!cached) showMsg(e.offline ? 'Offline dan belum ada daftar proyek tersimpan. Buka sekali saat online.' : (e.message || 'Gagal.'), 'err');
        });
    }
    function renderProjects(list) {
        var h = '';
        list.forEach(function (p) {
            h += '<div class="card prj" data-id="' + p.id + '"><div class="nm">' + esc(p.name) + '</div>' +
                 '<div class="sub">' + esc(p.status || '') + (p.location ? ' &middot; ' + esc(p.location) : '') + ' &middot; ' + (p.progress || 0) + '%</div>' +
                 '<div class="bar"><i style="width:' + (p.progress || 0) + '%"></i></div></div>';
        });
        $('prjList').innerHTML = h || '<div class="card">Belum ada proyek.</div>';
        var cards = $('prjList').querySelectorAll('.prj');
        for (var i = 0; i < cards.length; i++) {
            cards[i].onclick = (function (el) {
                return function () {
                    var id = parseInt(el.getAttribute('data-id'), 10);
                    for (var k = 0; k < list.length; k++) if (list[k].id === id) openProject(list[k]);
                };
            })(cards[i]);
        }
    }

    // ------------------------------------------------------------
    // 3. Form progress
    // ------------------------------------------------------------
    function openProject(p) {
        cur = p; hideMsg();
        $('vList').className = 'hidden'; $('vForm').className = '';
        $('btnBack').className = ''; $('ttl').textContent = p.name;
        $('fDate').value = todayStr();
        setPct(p.progress || 0);
        $('fNotes').value = '';
        clearPhotos(); geo = null; $('btnGps').textContent = 'Lokasi: -';
        renderQueueBox(); loadHistory();
    }
    function backToList() {
        cur = null; $('vForm').className = 'hidden'; $('vList').className = '';
        $('btnBack').className = 'sec hidden'; $('ttl').textContent = 'Progress Proyek'; hideMsg();
    }
    function setPct(v) {
        v = Math.max(0, Math.min(100, parseFloat(v) || 0));
        $('fRange').value = Math.round(v); $('fPct').value = v; $('lblPct').textContent = v;
    }

    // Kompres foto: sisi terpanjang <= 1280px, JPEG 75%. Gagal (mis. HEIC) -> pakai file asli bila < 5 MB.
    function compress(file) {
        return new Promise(function (res, rej) {
            var img = new Image(), url = URL.createObjectURL(file);
            img.onload = function () {
                var w = img.naturalWidth, h = img.naturalHeight, r = Math.min(1, MAX_SIDE / Math.max(w, h));
                var c = document.createElement('canvas'); c.width = Math.round(w * r); c.height = Math.round(h * r);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                c.toBlob(function (b) { b ? res(b) : rej(new Error('compress')); }, 'image/jpeg', JPEG_Q);
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                if (file.size < 5 * 1024 * 1024 && /^image\/(jpeg|png|webp)$/.test(file.type)) res(file); else rej(new Error('format'));
            };
            img.src = url;
        });
    }
    function renderThumbs() {
        var h = '';
        photos.forEach(function (p, i) { h += '<div><img src="' + p.url + '"><b data-i="' + i + '">&times;</b></div>'; });
        $('thumbs').innerHTML = h;
        var xs = $('thumbs').querySelectorAll('b');
        for (var i = 0; i < xs.length; i++) xs[i].onclick = function () {
            var k = parseInt(this.getAttribute('data-i'), 10); URL.revokeObjectURL(photos[k].url); photos.splice(k, 1); renderThumbs();
        };
    }
    function clearPhotos() { photos.forEach(function (p) { URL.revokeObjectURL(p.url); }); photos = []; renderThumbs(); }

    function getGps() {
        if (!navigator.geolocation) { showMsg('GPS tidak tersedia di perangkat ini.', 'err'); return; }
        $('btnGps').textContent = 'Lokasi: mencari...';
        navigator.geolocation.getCurrentPosition(function (pos) {
            geo = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            $('btnGps').textContent = 'Lokasi: ' + geo.lat.toFixed(5) + ', ' + geo.lng.toFixed(5);
        }, function (err) {
            // [DIUBAH] tampilkan alasan gagal agar mudah ditelusuri. GPS opsional: laporan tetap bisa disimpan tanpa lokasi.
            geo = null; $('btnGps').textContent = 'Lokasi: gagal (ketuk untuk ulangi)';
            var why = 'Lokasi gagal diambil.';
            if (!window.isSecureContext) why = 'Lokasi hanya bisa dipakai lewat HTTPS. Alamat saat ini bukan HTTPS.';
            else if (err && err.code === 1) why = 'Izin lokasi ditolak. Aktifkan izin lokasi untuk situs ini di pengaturan browser / aplikasi, lalu ulangi.';
            else if (err && err.code === 2) why = 'Posisi tidak tersedia. Nyalakan GPS / Lokasi di HP, lalu coba di tempat terbuka.';
            else if (err && err.code === 3) why = 'Pencarian lokasi terlalu lama (timeout). Coba lagi di tempat terbuka.';
            showMsg(why + ' Laporan tetap bisa disimpan tanpa lokasi.', 'err');
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    }

    function saveReport() {
        if (window.formAccess < ACCESS_ADD) { showMsg('Anda tidak memiliki hak akses untuk mencatat progress.', 'err'); return; }
        if (!cur) return;
        var pct = parseFloat($('fPct').value);
        if (isNaN(pct) || pct < 0 || pct > 100) { showMsg('Persentase harus 0 - 100.', 'err'); return; }
        if (!$('fDate').value) { showMsg('Tanggal wajib diisi.', 'err'); return; }

        var item = {
            uuid: uuid(), project_id: cur.id, project_name: cur.name, progress_date: $('fDate').value, percent: pct,
            notes: $('fNotes').value, lat: geo ? geo.lat : '', lng: geo ? geo.lng : '',
            photos: photos.map(function (p) { return p.blob; }), created: Date.now(), error: ''
        };
        $('btnSave').disabled = true;
        // Selalu simpan ke antrean dulu (aman bila sinyal putus saat upload), baru sinkron
        qPut(item).then(function () {
            clearPhotos(); $('fNotes').value = ''; geo = null; $('btnGps').textContent = 'Lokasi: -';
            return renderQueueBox();
        }).then(function () { return syncQueue(true); }).then(function () {
            $('btnSave').disabled = false;
        }, function () {
            $('btnSave').disabled = false;
            showMsg('Gagal menyimpan ke penyimpanan perangkat (ruang penuh / mode privat).', 'err');
        });
    }

    // ------------------------------------------------------------
    // 4. Antrean & sinkronisasi
    // ------------------------------------------------------------
    function buildForm(it) {
        var fd = new FormData();
        fd.append('task', 'save_progress');
        fd.append('client_uuid', it.uuid); fd.append('project_id', it.project_id);
        fd.append('progress_date', it.progress_date); fd.append('percent', it.percent);
        fd.append('notes', it.notes || ''); fd.append('latitude', it.lat); fd.append('longitude', it.lng);
        (it.photos || []).forEach(function (b, i) { fd.append('photos[]', b, 'foto' + (i + 1) + '.jpg'); });
        return fd;
    }

    function syncQueue(announce) {
        if (syncing) return Promise.resolve();
        if (!navigator.onLine) { return updateQueuePill().then(function () { if (announce) showMsg('Offline: laporan tersimpan di HP dan akan dikirim saat ada sinyal.', 'ok'); }); }
        syncing = true;
        return qAll().then(function (items) {
            items = items.filter(function (i) { return !i.error; }).sort(function (a, b) { return a.created - b.created; });
            var sent = 0, stopMsg = '';
            return items.reduce(function (chain, it) {
                return chain.then(function () {
                    if (stopMsg) return;
                    return api(null, buildForm(it)).then(function (res) {
                        if (res.success) { sent++; return qDel(it.uuid); }
                        it.error = res.message || 'Ditolak server.';        // validasi gagal: jangan dicoba otomatis lagi
                        return qPut(it);
                    }, function (e) {
                        if (e.auth) stopMsg = 'Sesi berakhir. Laporan tetap aman di HP - buka ulang halaman, login, lalu kirim lagi.';
                        else if (e.offline) stopMsg = 'Sinyal terputus. Laporan tersimpan di HP dan akan dicoba lagi.';
                        else stopMsg = e.message || 'Gagal mengirim.';
                    });
                });
            }, Promise.resolve()).then(function () {
                syncing = false;
                if (stopMsg) showMsg(stopMsg, 'err');
                else if (sent > 0) showMsg(sent + ' laporan terkirim.', 'ok');
                return Promise.all([renderQueueBox(), cur ? loadHistory() : null, sent > 0 ? refreshProjectsQuiet() : null]);
            });
        }).catch(function () { syncing = false; });
    }
    function refreshProjectsQuiet() {
        return api({ task: 'list_projects' }).then(function (res) {
            if (res.success) { try { localStorage.setItem(LS_PROJECTS, JSON.stringify(res.data)); } catch (e) {} renderProjects(res.data); }
        }, function () {});
    }

    function updateQueuePill() {
        return qAll().then(function (items) {
            var p = $('pillQ');
            if (items.length) { p.textContent = items.length + ' antre'; p.className = 'pill q'; } else { p.className = 'pill q hidden'; }
            return items;
        });
    }
    function renderQueueBox() {
        return updateQueuePill().then(function (items) {
            var h = '';
            items.filter(function (i) { return !cur || i.project_id === cur.id; }).forEach(function (it) {
                h += '<div class="card"><b>' + esc(it.percent) + '%</b> &middot; ' + esc(it.progress_date) + ' &middot; ' + (it.photos || []).length + ' foto' +
                     '<div class="meta" style="color:' + (it.error ? '#ff8a7d' : '#e6a817') + ';font-size:13px;">' +
                     (it.error ? 'DITOLAK: ' + esc(it.error) : 'Menunggu dikirim...') + '</div>' +
                     (it.error ? '<div style="margin-top:8px"><button class="danger" data-del="' + esc(it.uuid) + '">Hapus dari antrean</button></div>' : '') + '</div>';
            });
            $('qBox').innerHTML = h ? '<h2>Antrean (belum terkirim)</h2>' + h : '';
            var ds = $('qBox').querySelectorAll('[data-del]');
            for (var i = 0; i < ds.length; i++) ds[i].onclick = function () {
                qDel(this.getAttribute('data-del')).then(renderQueueBox);
            };
        });
    }

    // ------------------------------------------------------------
    // 5. Riwayat (online saja)
    // ------------------------------------------------------------
    function loadHistory() {
        if (!cur) return Promise.resolve();
        $('hist').innerHTML = '<div class="card">Memuat...</div>';
        return api({ task: 'list_progress', project_id: cur.id }).then(function (res) {
            if (!res.success) { $('hist').innerHTML = '<div class="card">' + esc(res.message || 'Gagal memuat riwayat.') + '</div>'; return; }
            var h = '';
            res.data.forEach(function (r) {
                h += '<div class="card hist"><span class="pct">' + esc(r.percent) + '%</span> <span class="meta">' + esc(r.progress_date) +
                     ' &middot; ' + esc(r.created_by_name || '-') + '</span>' +
                     (r.notes ? '<div>' + esc(r.notes) + '</div>' : '') + '<div class="thumbs">';
                (r.photos || []).forEach(function (pid) {
                    h += '<a href="' + CRD + '?task=photo&id=' + pid + '" target="_blank"><img loading="lazy" src="' + CRD + '?task=photo&id=' + pid + '"></a>';
                });
                h += '</div></div>';
            });
            $('hist').innerHTML = h || '<div class="card">Belum ada riwayat progress.</div>';
        }, function (e) {
            $('hist').innerHTML = '<div class="card">' + (e.offline ? 'Offline - riwayat tidak tersedia.' : esc(e.message || 'Gagal.')) + '</div>';
        });
    }

    // ------------------------------------------------------------
    // 6. Init
    // ------------------------------------------------------------
    $('btnBack').onclick = backToList;
    $('btnGps').onclick = getGps;
    $('btnSave').onclick = saveReport;
    $('btnPhoto').onclick = function () {
        if (photos.length >= MAX_PHOTOS) { showMsg('Maksimal ' + MAX_PHOTOS + ' foto.', 'err'); return; }
        $('fPhoto').click();
    };
    $('fPhoto').onchange = function () {
        var files = Array.prototype.slice.call(this.files || []), self = this;
        files.reduce(function (chain, f) {
            return chain.then(function () {
                if (photos.length >= MAX_PHOTOS) return;
                return compress(f).then(function (b) { photos.push({ blob: b, url: URL.createObjectURL(b) }); },
                                        function () { showMsg('Foto ' + f.name + ' tidak dapat diproses (gunakan JPG/PNG).', 'err'); });
            });
        }, Promise.resolve()).then(function () { renderThumbs(); self.value = ''; });
    };
    $('fRange').oninput = function () { setPct(this.value); };
    $('fPct').onchange = function () { setPct(this.value); };

    window.addEventListener('online', function () { updateNet(); syncQueue(false); });
    window.addEventListener('offline', updateNet);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) syncQueue(false); });   // iOS tanpa Background Sync

    updateNet();
    updateQueuePill();
    loadProjects();
    syncQueue(false);

    // Minta penyimpanan permanen agar antrean (foto) tidak dihapus browser (terutama iOS/Safari)
    if (navigator.storage && navigator.storage.persist) { navigator.storage.persist(); }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/proyek/sw.progress.js', { scope: '/proyek/' }).catch(function () {});
    }
})();