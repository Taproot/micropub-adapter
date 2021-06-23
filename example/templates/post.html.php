<?php

use BarnabyWalters\Mf2 as M;

/** @var array $post The post to display, structured as a microformats2 canonical JSON array, with some additional internal top-level properties. */

?>
<!DOCTYPE html>
<html>
	<head>
		<title></title>
	</head>
	<body>
		<main class="h-entry">
			<?php if (M\getProp($post, 'name')): ?>
				<h1 class="p-name"><a class="u-url" href="<?= htmlspecialchars(M\getProp($post, 'url')) ?>"><?= htmlspecialchars(M\getProp($post, 'name')) ?></a></h1>
			<?php endif ?>
			
			<?php if (M\getProp($post, 'summary')): ?>
				<p class="p-summary"><?= htmlspecialchars(M\getProp($post, 'summary')) ?></p>
			<?php endif ?>

			<?php if (isset($post['properties']['content'][0]['html'])): ?>
				<div class="e-content"><?= $post['properties']['content'][0]['html'] ?></div>
			<?php elseif (M\getPlaintext($post, 'content')): ?>
				<div class="p-content"><?= htmlspecialchars(M\getPlaintext($post, 'content')) ?></div>
			<?php endif ?>

			<?php foreach (M\getPlaintextArray($post, 'photo', []) as $photo): ?>
				<?php if (is_array($photo)): ?>
					<img class="u-photo" src="<?= htmlspecialchars($photo['value']) ?>" alt="<?= htmlspecialchars($photo['alt']) ?>" />
				<?php else: ?>

				<?php endif ?>
					<img class="u-photo" src="<?= htmlspecialchars($photo) ?>" />
			<?php endforeach ?>

			<ul>
				<?php foreach (M\getPlaintextArray($post, 'category', []) as $category): ?>
				<li class="p-category"><?= htmlspecialchars($category) ?></li>
				<?php endforeach ?>
			</ul>


<?php /* 
			<?php if (M\getProp($post, '')): ?>
				<?= htmlspecialchars(M\getProp($post, '')) ?>
			<?php endif ?>

			<?php if (M\getProp($post, '')): ?>
				<?= M\getProp($post, '') ?>
			<?php endif ?>
				*/ ?>

		</main>
	</body>
</html>
