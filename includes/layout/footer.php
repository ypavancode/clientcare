    </main>
<?php if (!empty($isPjax)): ?>
<?php if (!empty($useCharts)): ?><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script><?php endif; ?>
<?php if (!empty($useDtButtons)): ?><script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script><script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script><script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.colVis.min.js"></script><script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script><script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script><?php endif; ?>
<?php if (!empty($pageScripts)) echo $pageScripts; ?>
<?php return; endif; ?>
    <footer class="app-footer">
      <span>&copy; <?= date('Y') ?> <?= e(company_name()) ?>. All rights reserved.</span>
      <span class="text-muted">v<?= APP_VERSION ?> · Server time: <?= date('d-M-Y h:i A') ?></span>
    </footer>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<?php if (!empty($useDtButtons)): ?>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.colVis.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<?php endif; ?>
<?php if (!empty($useCharts)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script>
window.CRM = {
  baseUrl: <?= json_encode(BASE_URL) ?>,
  csrf: <?= json_encode(csrf_token()) ?>,
  user: <?= json_encode(['id' => $currentUser['id'], 'name' => $currentUser['name'], 'role' => $currentUser['role']]) ?>
};
</script>
<script src="<?= asset('js/app.js') ?>?v=<?= APP_VERSION ?>"></script>
<?php if (!empty($pageScripts)) echo $pageScripts; ?>
</body>
</html>
