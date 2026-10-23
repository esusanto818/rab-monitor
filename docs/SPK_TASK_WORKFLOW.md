# SPK berbasis Task Proyek

## Tujuan
Menghubungkan Surat Perintah Kerja (SPK) dengan task pada `public.project_tasks` tanpa menghapus fungsi lama SPK untuk mengatur pengeluaran material gudang.

## Alur
1. **Proyek → Task Proyek**: pilih proyek, lalu pilih satu atau lebih task milik proyek tersebut.
2. **Pembuatan SPK**: simpan nomor/tanggal, pelaksana, uraian, task, dan material (opsional). Task wajib; material opsional untuk pekerjaan yang tidak membutuhkan pengeluaran gudang.
3. **Persetujuan**: Draft → Menunggu Persetujuan → Disetujui atau Ditolak. SPK yang ditolak dapat diedit dan diajukan ulang.
4. **Penerbitan & pelaksanaan**: setelah disetujui, terbitkan SPK. Status material lama tetap `Diterbitkan`; workflow menjadi `Pelaksanaan`. Jika SPK memuat material, aturan pembayaran termin dan kuota RAB yang lama tetap diberlakukan.
5. **Verifikasi**: pelaksana mengajukan `Menunggu Verifikasi`; pemeriksa menyelesaikan SPK menjadi `Selesai`.

## Perubahan
- `database/spk_task_workflow.sql`: migration idempotent untuk `workflow_status`, relasi `spk_tasks`, dan audit `spk_workflow_log`.
- `php-crud/spkCRD.php`: validasi task per proyek, mencegah task dipakai SPK aktif lain, endpoint workflow, dan audit status.
- `js/spk.js`: pilih task proyek, daftar task SPK, status workflow, dan tombol aksi per tahap.
- `pdf/spk.php`: mencetak status workflow dan task dalam lingkup SPK.

## Hak akses
- Level akses >= 2: membuat/mengubah Draft, mengajukan persetujuan, mengajukan verifikasi.
- Level akses >= 3: menyetujui/menolak, menerbitkan, memverifikasi selesai, membatalkan/menghapus sesuai aturan modul.
- Pemeriksaan tetap dilakukan di backend, bukan hanya menyembunyikan tombol UI.

## Langkah penerapan
1. Backup database terlebih dahulu.
2. Jalankan `database/spk_task_workflow.sql` pada database PostgreSQL yang digunakan aplikasi.
3. Deploy file PHP/JS/PDF dari branch ini bersama migration.
4. Pastikan menu SPK yang sudah ada tetap menunjuk ke `views/spk.php`.
5. Uji semua skenario di bawah pada staging/salinan database sebelum produksi.

## Checklist uji regresi
- [ ] Buka SPK dan muat daftar yang sudah ada.
- [ ] Buat SPK dengan task dari proyek yang dipilih.
- [ ] Pastikan task dari proyek lain ditolak oleh backend.
- [ ] Pastikan task yang sudah terikat SPK aktif tidak bisa dipilih pada SPK lain.
- [ ] Simpan SPK tanpa material; pastikan task tetap wajib.
- [ ] Simpan SPK dengan material; pastikan validasi material/RAB tetap berjalan.
- [ ] Ajukan Draft untuk persetujuan.
- [ ] Setujui SPK dengan level akses 3; pastikan level di bawahnya ditolak.
- [ ] Tolak SPK, edit Draft, lalu ajukan ulang.
- [ ] Pastikan SPK belum bisa diterbitkan sebelum disetujui.
- [ ] Terbitkan SPK dan pastikan status material menjadi `Diterbitkan`, workflow `Pelaksanaan`.
- [ ] Uji pengeluaran material dari SPK dengan aturan kuota lama.
- [ ] Ajukan verifikasi dan selesaikan SPK.
- [ ] Cetak SPK; pastikan task dan status workflow ikut tercetak.
- [ ] Pastikan audit workflow tercatat pada `spk_workflow_log`.
- [ ] Pastikan SPK lama (sebelum migration) tetap terbaca dan dapat dipakai sesuai statusnya.

## Catatan
- Migration perlu dijalankan sebelum versi PHP/JS baru diaktifkan, karena endpoint membaca kolom/tabel baru.
- Satu task hanya boleh berada pada satu SPK aktif; SPK Ditolak, Dibatalkan, atau Selesai tidak lagi mengunci task.
- Perubahan ini tidak mengubah atau menghitung progres task secara otomatis. Tidak ada perhitungan progres berbobot.
- Pemeriksaan di sini bersifat review kode statis; uji browser dan database nyata tetap perlu dijalankan di lingkungan aplikasi.
