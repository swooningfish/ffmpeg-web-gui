<?php /* The latest conversions, with links to each one and delete buttons. */ ?>
<?php if ($recentJobs): ?>
<section class="recent" aria-labelledby="recent_title">
	<h2 id="recent_title">Recent conversions</h2>
	<div class="table-wrap">
		<table>
			<thead>
				<tr>
					<th scope="col">Video</th>
					<th scope="col">Status</th>
					<th scope="col">Uploaded</th>
					<th scope="col"><span class="visually-hidden">Actions</span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($recentJobs as $item): ?>
				<tr>
					<td class="name"><a href="?job=<?php echo h($item['id']); ?>"><?php echo h($item['name']); ?></a></td>
					<td><span class="badge badge-<?php echo h($item['status']); ?>"><?php echo h(jobStatusLabel($item['status'])); ?></span></td>
					<td><time datetime="<?php echo h(date('c', $item['created'])); ?>"><?php echo h(date('j M Y, H:i', $item['created'])); ?></time></td>
					<td class="row-actions">
						<?php if ($item['status'] !== 'running'): ?>
						<form action="" method="post" data-confirm="Delete <?php echo h($item['name']); ?> and its files?">
							<input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
							<input type="hidden" name="action" value="delete_job">
							<input type="hidden" name="job" value="<?php echo h($item['id']); ?>">
							<button type="submit" class="secondary outline" aria-label="Delete <?php echo h($item['name']); ?>">Delete</button>
						</form>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php if ($config['job_retention_days'] > 0): ?>
	<p><small>Finished conversions are deleted automatically <?php echo (int)$config['job_retention_days']; ?> day(s) after they finish.</small></p>
	<?php endif; ?>
</section>
<?php endif; ?>
