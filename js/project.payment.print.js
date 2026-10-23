/*
  Document      : project.payment.print.js
  Description   : Template & Controller Cetak Tanda Terima Pembayaran Termin + Lampiran Detail AI Per Proyek.
                   - Lembar 1: Tanda Terima Pembayaran & Rincian Alokasi Termin.
                   - Lembar 2 dst: Lampiran Analisis Varian AI per Proyek (tiap proyek di halaman baru).
  Backend Source: /rab-project/php-crud/project.paymentCRD.php (task: load_payment & get_latest_project_variance)
*/

(function () {
    'use strict';

    var urlParams = new URLSearchParams(window.location.search);
    var paymentId = parseInt(urlParams.get('id'), 10) || parseInt(window.PAYMENT_ID, 10) || 0;
    var CRD = '/rab-project/php-crud/project.paymentCRD.php';
    var root = document.getElementById('receipt');

    if (!paymentId) {
        if (root) {
            root.innerHTML = '<div style="color:red; padding:20px; font-family:sans-serif; text-align:center;">ID Pembayaran tidak ditemukan dalam URL.</div>';
        }
        return;
    }

    function esc(v) { 
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;'); 
    }
    
    function n(v) { var x = parseFloat(v); return isFinite(x) ? x : 0; }
    
    function money(v) { 
        return n(v).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); 
    }
    
    function dateId(v) { 
        if (!v) return '-'; 
        var s = String(v).substring(0, 10), p = s.split('-'); 
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : esc(v); 
    }
    
    var METHOD_LABEL = { BANK_TRANSFER: 'Transfer Bank', CASH: 'Tunai', GIRO: 'Giro', CEK: 'Cek' };
    function methodLabel(v) { return METHOD_LABEL[String(v || '').toUpperCase()] || v || '-'; }

    function post(params, done) {
        var xhr = new XMLHttpRequest();
        var body = Object.keys(params).map(function(k){
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');

        xhr.open('POST', CRD, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            var r;
            try { r = JSON.parse(xhr.responseText); } catch (e) {
                done(new Error('Respons server tidak valid (bukan JSON).'));
                return;
            }
            if (xhr.status < 200 || xhr.status >= 300) {
                done(new Error(r.message || 'Permintaan gagal.'));
                return;
            }
            done(null, r);
        };
        xhr.onerror = function() { done(new Error('Gagal terhubung ke server.')); };
        xhr.send(body);
    }

    function fail(msg) {
        if (root) root.innerHTML = '<div class="err" style="color:#c0392b; padding:30px; text-align:center;">' + esc(msg) + '</div>';
    }

    // Generator HTML Lampiran Halaman Analisis AI per Proyek
    function aiPageHtml(d, projectName, projectId) {
        if (!d) {
            return '<div class="sheet page-break">' +
                   '<div class="hdr"><div class="l"><div class="co-name">RAB-Monitor</div><div class="co-addr">Sistem Monitoring Proyek</div></div><div class="r"><b>LAMPIRAN ANALISIS AI</b><div>Proyek: ' + esc(projectName) + '</div></div></div>' +
                   '<h2 style="margin-top:20px; font-size:16px; color:#00796b;">&#9881; AI Cost &amp; Schedule Variance Analysis &mdash; ' + esc(projectName) + '</h2>' +
                   '<p style="margin-top:20px; color:#555;">Belum ada hasil analisis AI tersimpan untuk proyek ini (ID: ' + esc(projectId) + '). Jalankan Analisis Varian AI dari halaman detail, lalu cetak kembali.</p>' +
                   '<div class="footer">Dokumen dihasilkan oleh RAB-Monitor &middot; Lampiran Proyek #' + esc(projectId) + '</div>' +
                   '</div>';
        }

        var risk = String(d.risk_level || 'UNKNOWN').toUpperCase();
        var riskColor = '#2e9e4f';
        if (risk === 'MEDIUM') riskColor = '#e6a817';
        else if (risk === 'HIGH' || risk === 'CRITICAL') riskColor = '#e05353';

        var rec = d.recommendations, arr = Array.isArray(rec) ? rec : [];
        if (typeof rec === 'string') { try { arr = JSON.parse(rec); } catch (e) { arr = []; } }
        var recHtml = arr.length ? arr.map(function(r){ return '<li style="margin-bottom:6px;">' + esc(r) + '</li>'; }).join('') : '<li>Tidak ada rekomendasi khusus.</li>';

        return '<div class="sheet page-break">' +
               '<div class="hdr"><div class="l"><div class="co-name">RAB-Monitor</div><div class="co-addr">Sistem Monitoring Proyek</div></div><div class="r"><b>LAMPIRAN ANALISIS AI</b><div>Proyek: ' + esc(d.project_name || projectName) + '</div></div></div>' +
               
               '<div style="margin-top:20px; background:#111b24; border:1px solid #00bcd4; border-radius:6px; padding:18px; color:#fff !important;">' +
               '  <div style="font-weight:bold; font-size:15px; color:#00bcd4; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; gap:10px;">' +
               '    <span>&#9881; AI Cost &amp; Schedule Variance Analysis &mdash; ' + esc(d.project_name || projectName) + '</span>' +
               '    <span style="background:' + riskColor + '; color:#fff; padding:3px 10px; border-radius:3px; font-size:13px; white-space:nowrap;">RISK: ' + esc(risk) + '</span>' +
               '  </div>' +
               '  <div style="font-size:14px; line-height:22px; margin-bottom:12px; color:#fff !important;"><b style="color:#00bcd4;">Ringkasan Eksekutif:</b> ' + esc(d.ai_summary || '-') + '</div>' +
               '  <div style="font-size:14px; line-height:22px; color:#fff !important;"><b style="color:#00bcd4;">Rekomendasi Mitigasi:</b><ul style="margin:6px 0 0 18px; padding:0; color:#fff !important;">' + recHtml + '</ul></div>' +
               '</div>' +
               '<p class="muted" style="margin-top:15px;">Analisis AI merupakan informasi pendukung dan perlu ditinjau bersama data aktual proyek. (Waktu Analisis: ' + dateId(d.created_at) + ')</p>' +
               '<div class="footer">Dokumen dihasilkan oleh RAB-Monitor &middot; Lampiran Analisis Proyek</div>' +
               '</div>';
    }

    function renderAll(data) {
        var h = data.header || {}, items = data.items || [], cur = h.currency_code || 'IDR', foreign = (cur !== 'IDR'), allocCur = 0;
        var projects = [], seen = {};
        
        items.forEach(function(i) {
            var id = String(i.project_id);
            if (!seen[id]) {
                seen[id] = true;
                projects.push({ id: i.project_id, name: i.project_name || ('Proyek #' + id) });
            }
        });

        var rows = items.map(function(i) {
            return '<tr>' +
                   '<td>' + esc(i.project_name) + '</td>' +
                   '<td>' + esc(i.termin_name) + (i.percentage !== null && i.percentage !== undefined ? ' (' + money(i.percentage) + '%)' : '') + '</td>' +
                   '<td>' + esc(i.inv_no) + '</td>' +
                   '<td class="r-al">' + money(i.invoice_amount) + '</td>' +
                   '<td class="r-al">' + money(i.amount_paid) + '</td>' +
                   (foreign ? '<td class="r-al">' + money(i.amount_cur) + '</td>' : '') +
                   '</tr>';
        }).join('');

        if (!rows) {
            rows = '<tr><td colspan="' + (foreign ? 6 : 5) + '" style="text-align:center">Tidak ada rincian alokasi termin.</td></tr>';
        }

        var totals = '<tr><td colspan="4" class="r-al"><b>Total Dialokasikan (IDR)</b></td><td class="r-al"><b>' + money(h.allocated_amount) + '</b></td>' + (foreign ? '<td></td>' : '') + '</tr>';
        if (n(h.excess_amount) > 0.01) {
            totals += '<tr><td colspan="4" class="r-al"><b>Lebih Bayar (IDR)</b></td><td class="r-al">' + money(h.excess_amount) + '</td>' + (foreign ? '<td></td>' : '') + '</tr>';
        }
        totals += '<tr><td colspan="4" class="r-al"><b>Total Diterima (' + esc(cur) + ')</b></td><td class="r-al"><b>' + money(h.total_amount_cur !== undefined ? h.total_amount_cur : h.total_amount) + '</b></td>' + (foreign ? '<td></td>' : '') + '</tr>';
        if (foreign) {
            totals += '<tr><td colspan="4" class="r-al"><b>Nilai setara IDR</b></td><td class="r-al">' + money(h.total_amount) + '</td><td></td></tr>';
        }

        // Lembar 1: Tanda Terima Pembayaran Utama
        var html = '<div class="sheet">' +
            '<header class="hdr"><div class="l"><div class="co-name">RAB-Monitor</div><div class="co-addr">Sistem Monitoring Proyek</div></div><div class="r"><b>TANDA TERIMA PEMBAYARAN</b><div>No. ' + esc(h.payment_no) + '</div></div></header>' +
            '<h1 class="title" style="text-align:center; font-size:18px; margin:15px 0;">TANDA TERIMA</h1>' +
            '<p>Telah diterima pembayaran dari:</p>' +
            '<table class="meta">' +
            '<tr><td class="k">Nama Client</td><td><b>' + esc(h.client_name) + '</b></td><td class="k">Tanggal Pembayaran</td><td><b>' + dateId(h.payment_date) + '</b></td></tr>' +
            '<tr><td class="k">Metode Pembayaran</td><td><b>' + esc(methodLabel(h.payment_method)) + '</b></td><td class="k">Mata Uang</td><td><b>' + esc(cur) + (foreign ? ' (kurs ' + money(h.exch_rate) + ')' : '') + '</b></td></tr>' +
            '<tr><td class="k">Akun Penerimaan</td><td><b>' + esc([h.account_code, h.account_name].filter(Boolean).join(' - ') || '-') + '</b></td><td class="k">Referensi</td><td><b>' + esc(h.reference_no || '-') + '</b></td></tr>' +
            '</table>' +
            '<table class="items"><thead><tr><th>Proyek</th><th>Termin</th><th>No. Invoice</th><th class="r-al">Tagihan (IDR)</th><th class="r-al">Dibayar (IDR)</th>' + (foreign ? '<th class="r-al">Dibayar (' + esc(cur) + ')</th>' : '') + '</tr></thead><tbody>' + rows + '</tbody><tfoot>' + totals + '</tfoot></table>' +
            (h.notes ? '<p style="margin-top:10px;"><b>Catatan:</b> ' + esc(h.notes) + '</p>' : '') +
            '<div class="sign"><div><p>Diterima oleh,</p><div class="line">' + esc(h.client_name) + '</div></div><div><p>Diterima dari / Petugas,</p><div class="line">' + esc(h.created_by_name || 'Administrator System') + '</div></div></div>' +
            '<div class="footer">Dokumen dihasilkan oleh RAB-Monitor &middot; ' + esc(h.payment_no) + '</div>' +
            '</div>';

        if (root) root.innerHTML = html;

        if (!projects.length) return;

        // Ambil data analisis AI secara asinkron untuk setiap proyek unik dan susun di lembar berikutnya
        var completed = 0;
        var aiPages = new Array(projects.length);

        projects.forEach(function (p, idx) {
            post({ task: 'get_latest_project_variance', project_id: p.id }, function (err, res) {
                var d = (!err && res && res.success && res.data && res.data.found) ? res.data.data : null;
                aiPages[idx] = aiPageHtml(d, p.name, p.id);
                completed++;
                if (completed === projects.length) {
                    if (root) root.innerHTML += aiPages.join('');
                }
            });
        });
    }

    post({ task: 'load_payment', id: paymentId }, function (err, res) {
        if (err) {
            fail('Gagal memuat tanda terima: ' + err.message);
            return;
        }
        if (!res || !res.success || !res.data || !res.data.header) {
            fail((res && res.message) || 'Data pembayaran tidak ditemukan.');
            return;
        }
        renderAll(res.data);
    });
})();