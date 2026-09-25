<?php
require_once __DIR__ . '/../includes/init.php';
Auth::logout();
session_start();
flash('success', 'You have been logged out.');
redirect('auth/login.php');
