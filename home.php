<section class="hero">
    <div class="container hero-inner">
        <div class="eyebrow">Pemesanan tanpa akun</div>
        <h1>Mudah.order, dari toko favorit Anda.</h1>
        <p>PesanMenu membantu UMKM: pelanggan membuka link toko, memilih menu, dan mengirim pesanan dengan status yang transparan.</p>
        <div class="hero-actions row">
            <a class="btn btn-primary" href="#toko">Lihat toko</a>
            <a class="btn btn-secondary" href="<?= e(url('/lacak')) ?>">Lacak pesanan</a>
        </div>
    </div>
</section>
<section class="page" id="toko">
    <div class="container">
        <div class="page-header row between">
            <div>
                <div class="eyebrow">Mulai belanja</div>
                <h1>Pilih toko</h1>
                <p>Toko aktif akan tampil di sini.</p>
            </div>
        </div>
        <?php if (!$sellers): ?>
            <div class="card empty">
                <strong>Belum ada toko aktif</strong>
                <span>Penjual dapat membuat toko melalui dashboard.</span>
            </div>
        <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($sellers as $store): ?>
                    <a class="card card-pad" href="<?= e(url('/toko/' . rawurlencode($store['slug']))) ?>">
                        <div class="row between">
                            <span class="badge badge-info">Toko</span>
                            <span class="muted"><?= e($store['whatsapp'] ?: 'WhatsApp belum diisi') ?></span>
                        </div>
                        <h2 class="mt"><?= e($store['name']) ?></h2>
                        <p class="muted small"><?= e($store['address'] ?: 'Alamat toko belum diisi.') ?></p>
                        <span class="bold" style="color:var(--brand)">Lihat menu →</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
