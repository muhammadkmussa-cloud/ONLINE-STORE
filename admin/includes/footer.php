    </div><!-- /.app-content -->

    <footer class="app-footer">
      <small>
        &copy; <?= date('Y') ?> <?= e(setting('site_name', APP_NAME)) ?> ·
        Built with PHP &amp; Bootstrap.
      </small>
    </footer>
  </main><!-- /.app-main -->
</div><!-- /.app -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<?php if (!empty($loadChartJs)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="<?= e(asset_url('assets/js/script.js')) ?>"></script>
</body>
</html>
