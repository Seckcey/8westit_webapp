<?php
/** Renders the agent inventory JSON into readable sections. */
declare(strict_types=1);

function render_inventory(array $inv): void
{
    $sys  = $inv['system']  ?? [];
    $cpu  = $inv['cpu']     ?? [];
    $disks = $inv['disks']  ?? [];
    $net  = $inv['network'] ?? [];
    $soft = $inv['software'] ?? [];
    ?>
    <div class="inv-grid">
      <div>
        <h4>Machine</h4>
        <dl class="kv">
          <?php foreach (['manufacturer'=>'Manufacturer','model'=>'Model','serial'=>'Serial',
                          'ram_gb'=>'RAM (GB)','os'=>'OS','os_build'=>'Build',
                          'uptime'=>'Uptime'] as $k=>$label):
                if (isset($sys[$k])): ?>
            <dt><?= e($label) ?></dt><dd><?= e((string)$sys[$k]) ?></dd>
          <?php endif; endforeach; ?>
          <?php if (!empty($cpu['name'])): ?>
            <dt>CPU</dt><dd><?= e((string)$cpu['name']) ?> (<?= e((string)($cpu['cores'] ?? '?')) ?> cores)</dd>
          <?php endif; ?>
        </dl>
      </div>
      <div>
        <h4>Disks</h4>
        <table class="grid mini">
          <thead><tr><th>Drive</th><th>Size</th><th>Free</th></tr></thead>
          <tbody>
          <?php foreach ($disks as $d): ?>
            <tr><td><?= e((string)($d['drive'] ?? '?')) ?></td>
                <td><?= e((string)($d['size_gb'] ?? '?')) ?> GB</td>
                <td><?= e((string)($d['free_gb'] ?? '?')) ?> GB</td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <h4>Network adapters</h4>
    <table class="grid mini">
      <thead><tr><th>Adapter</th><th>IPv4</th><th>MAC</th></tr></thead>
      <tbody>
      <?php foreach ($net as $n): ?>
        <tr><td><?= e((string)($n['name'] ?? '')) ?></td>
            <td><?= e((string)($n['ipv4'] ?? '')) ?></td>
            <td><?= e((string)($n['mac'] ?? '')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($soft): ?>
    <details>
      <summary><h4 style="display:inline">Installed software (<?= count($soft) ?>)</h4></summary>
      <table class="grid mini">
        <thead><tr><th>Name</th><th>Version</th><th>Publisher</th></tr></thead>
        <tbody>
        <?php foreach ($soft as $s): ?>
          <tr><td><?= e((string)($s['name'] ?? '')) ?></td>
              <td><?= e((string)($s['version'] ?? '')) ?></td>
              <td><?= e((string)($s['publisher'] ?? '')) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </details>
    <?php endif;
}
