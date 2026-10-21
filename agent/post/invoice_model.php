<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$date = escapeSql($_POST['date']);
$category = intval($_POST['category']);
$scope = escapeSql($_POST['scope']);
$invoice_discount = floatval($_POST['invoice_discount'] ?? 0);
$recurring_discount = floatval($_POST['recurring_discount'] ?? 0);

$config_invoice_prefix = escapeSql($config_invoice_prefix);
