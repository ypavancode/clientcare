<?php
require_once __DIR__ . '/../includes/init.php';
redirect('status/index.php?edit=' . (int) get('id', 0));
