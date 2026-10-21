/*
  Document      : project.payment.print.js
  Description   : Isi halaman cetak Tanda Terima Pembayaran Termin (views/project.payment.print.php).
                  Tanpa ExtJS: ambil data lewat XMLHttpRequest ke php-crud/project.paymentCRD.php (task=load_payment),
                  lalu render ke <div id="receipt">. Mendukung pembayaran multi proyek & multi currency.
  Pemakaian     : window.PAYMENT_ID diisi oleh project.payment.print.php.
  Lokasi file   : /rab-project/js/project.payment.print.js
*/
(function () {
    var CRD = '/rab-project/php-crud/project.paymentCRD.php';
    // Isi identitas perusahaan untuk kop tanda terima
    var COMPANY_NAME = 'PT. NAMA PERUSAHAAN';              // GANTI sesuai perusahaan
    var COMPANY_ADDR = 'Alamat perusahaan, kota - telp';    // GANTI sesuai perusahaan

    var METHOD_LABEL = { BANK_TRANSFER: 'Transfer Bank', CASH: 'Tunai', GIRO: 'Giro', CEK: 'Cek' };
    var box = document.getElementById('receipt');

    function enc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function fmt(v) { return (parseFloat(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtRate(v) { return (parseFloat(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 }); }
    function fmtDate(s) {                                   // 'YYYY-MM-DD' -> 'DD/MM/YYYY'
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
        return m ? (m[3] + '/' + m[2] + '/' + m[1]) : (s || '-');
    }
    function showError(msg) { box.innerHTML = '<div class="err">' + enc(msg) + '</div>'; }

    // ---- terbilang (Bahasa Indonesia) ----
    var S = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    function sayInt(n) {
        n = Math.floor(n);
        if (n < 12) return S[n];
        if (n < 20) return sayInt(n - 10) + ' belas';
        if (n < 100) return sayInt(n / 10) + ' puluh' + (n % 10 ? ' ' + sayInt(n % 10) : '');
        if (n < 200) return 'seratus' + (n - 100 ? ' ' + sayInt(n - 100) : '');
        if (n < 1000) return sayInt(n / 100) + ' ratus' + (n % 100 ? ' ' + sayInt(n % 100) : '');
        if (n < 2000) return 'seribu' + (n - 1000 ? ' ' + sayInt(n - 1000) : '');
        if (n < 1e6) return sayInt(n / 1e3) + ' ribu' + (n % 1e3 ? ' ' + sayInt(n % 1e3) : '');
        if (n < 1e9) return sayInt(n / 1e6) + ' juta' + (n % 1e6 ? ' ' + sayInt(n % 1e6) : '');
        if (n < 1e12) return sayInt(n / 1e9) + ' miliar' + (n % 1e9 ? ' ' + sayInt(n % 1e9) : '');
        if (n < 1e15) return sayInt(n / 1e12) + ' triliun' + (n % 1e12 ? ' ' + sayInt(n % 1e12) : '');
        return String(n);
    }
    function terbilang(v) {
        v = Math.round((parseFloat(v) || 0) * 100) / 100;
        var whole = Math.floor(v), cents = Math.round((v - whole) * 100);
        var t = whole === 0 ? 'nol' : sayInt(whole);
        if (cents > 0) t += ' koma ' + (cents < 10 ? 'nol ' : '') + sayInt(cents);
        return t.charAt(0).toUpperCase() + t.slice(1) + ' rupiah';
    }

    function render(d) {
        var h = d.header, items = d.items || [];
        var cur = h.currency_code || 'IDR', foreign = (cur !== 'IDR');
        var rows = '', allocCur = 0, i;

        for (i = 0; i < items.length; i++) {
            var it = items[i];
            allocCur += parseFloat(it.amount_cur) || 0;
            rows += '<tr>' +
                    '<td>' + (i + 1) + '</td>' +
                    '<td>' + enc(it.project_name) + '</td>' +
                    '<td>' + enc(it.inv_no) + '<br/>' + enc(it.termin_name) + (it.percentage !== null ? ' (' + fmt(it.percentage) + '%)' : '') + '</td>' +
                    '<td class="r-al">' + fmt(it.invoice_amount) + '</td>' +
                    '<td class="r-al">' + fmt(it.amount_paid) + '</td>' +
                    '<td class="r-al">' + fmt(it.sisa_after) + '</td>' +
                    (foreign ? '<td class="r-al">' + fmt(it.amount_cur) + '</td>' : '') +
                    '</tr>';
        }
        if (!items.length) rows = '<tr><td colspan="' + (foreign ? 7 : 6) + '" style="text-align:center;">Tidak ada alokasi termin.</td></tr>';

        var colsBefore = 4;     // No, Proyek, Termin, Tagihan
        var totalRows =
            '<tr><td colspan="' + colsBefore + '" class="r-al"><b>Total Dialokasikan</b></td><td class="r-al"><b>' + fmt(h.allocated_amount) + '</b></td><td></td>' +
            (foreign ? '<td class="r-al"><b>' + fmt(allocCur) + '</b></td>' : '') + '</tr>';
        if (h.excess_amount > 0.01) {
            totalRows += '<tr><td colspan="' + colsBefore + '" class="r-al">Lebih Bayar (deposit client)</td><td class="r-al">' + fmt(h.excess_amount) + '</td><td></td>' +
                         (foreign ? '<td class="r-al">' + fmt((parseFloat(h.total_amount_cur) || 0) - allocCur) + '</td>' : '') + '</tr>';
        }
        totalRows += '<tr><td colspan="' + colsBefore + '" class="r-al"><b>Total Diterima</b></td><td class="r-al"><b>' + fmt(h.total_amount) + '</b></td><td></td>' +
                     (foreign ? '<td class="r-al"><b>' + fmt(h.total_amount_cur) + '</b></td>' : '') + '</tr>';

        var accountTxt = h.account_name ? (enc(h.account_code) + ' - ' + enc(h.account_name)) : '-';
        var html =
            '<div class="hdr">' +
              '<div class="l"><div class="co-name">' + enc(COMPANY_NAME) + '</div><div class="co-addr">' + enc(COMPANY_ADDR) + '</div></div>' +
              '<div class="r"><div class="title">TANDA TERIMA PEMBAYARAN</div><div>No: <b>' + enc(h.payment_no) + '</b></div></div>' +
            '</div>' +
            '<table class="meta" style="margin-top:10px;">' +
              '<tr><td class="k">Telah diterima dari</td><td class="s">:</td><td><b>' + enc(h.client_name) + '</b></td></tr>' +
              (h.client_address ? '<tr><td class="k">Alamat</td><td class="s">:</td><td>' + enc(h.client_address) + '</td></tr>' : '') +
              '<tr><td class="k">Tanggal</td><td class="s">:</td><td>' + fmtDate(h.payment_date) + '</td></tr>' +
              '<tr><td class="k">Metode</td><td class="s">:</td><td>' + enc(METHOD_LABEL[h.payment_method] || h.payment_method || '-') + '</td></tr>' +
              '<tr><td class="k">Akun Kas/Bank</td><td class="s">:</td><td>' + accountTxt + '</td></tr>' +
              (h.reference_no ? '<tr><td class="k">No. Referensi</td><td class="s">:</td><td>' + enc(h.reference_no) + '</td></tr>' : '') +
              (h.notes ? '<tr><td class="k">Catatan</td><td class="s">:</td><td>' + enc(h.notes) + '</td></tr>' : '') +
            '</table>' +
            '<div class="amount-box">Jumlah Diterima: IDR ' + fmt(h.total_amount) +
              (foreign ? ' <span style="font-weight:normal;font-size:12px;">(' + enc(cur) + ' ' + fmt(h.total_amount_cur) + ' &times; kurs ' + fmtRate(h.exch_rate) + ')</span>' : '') +
            '</div>' +
            '<div class="terbilang">Terbilang: ' + enc(terbilang(h.total_amount)) + '</div>' +
            '<div>Untuk pembayaran termin berikut:</div>' +
            '<table class="items"><thead><tr>' +
              '<th>No</th><th>Proyek</th><th>Termin</th><th class="r-al">Tagihan (IDR)</th><th class="r-al">Dibayar (IDR)</th><th class="r-al">Sisa Setelah Bayar</th>' +
              (foreign ? '<th class="r-al">Dibayar (' + enc(cur) + ')</th>' : '') +
            '</tr></thead><tbody>' + rows + totalRows + '</tbody></table>' +
            '<div class="sign">' +
              '<div>Yang Menyerahkan<br/><span class="line">' + enc(h.client_name) + '</span></div>' +
              '<div>Yang Menerima<br/><span class="line">' + enc(h.created_by_name || '') + '</span></div>' +
            '</div>';

        box.innerHTML = html;
        document.title = 'Tanda Terima ' + (h.payment_no || '');
    }

    // ---- ambil data ----
    if (!window.PAYMENT_ID) { showError('ID pembayaran tidak valid.'); return; }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', CRD, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        if (xhr.status !== 200) { showError('Gagal memuat data (HTTP ' + xhr.status + ').' + (xhr.status === 401 ? ' Sesi berakhir, silakan login ulang.' : '')); return; }
        var res;
        try { res = JSON.parse(xhr.responseText); } catch (e) { showError('Respons server tidak valid (cek error_log PHP).'); return; }
        if (!res.success) { showError(res.message || 'Gagal memuat data pembayaran.'); return; }
        try { render(res.data); } catch (e2) { showError('Gagal menampilkan tanda terima: ' + e2.message); }
    };
    xhr.onerror = function () { showError('Gagal menghubungi server.'); };
    xhr.send('task=load_payment&id=' + encodeURIComponent(window.PAYMENT_ID));
})();