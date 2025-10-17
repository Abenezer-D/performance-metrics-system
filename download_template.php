<?php
include 'config.php';
session_start();

// Check if user is admin
$is_admin = true; // Replace with actual admin check

if (!$is_admin) {
    header('HTTP/1.0 403 Forbidden');
    exit();
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=staff_targets_template.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add CSV headers
fputcsv($output, [
    'Staff Name',
    'KPI Name', 
    'Daily Target',
    'Weekly Target',
    'Monthly Target',
    'Quarter Target',
    'Quarter Number',
    'Quarter Year'
]);

// Add example data
fputcsv($output, [
    'John Doe',
    'Sales',
    '10',
    '50',
    '200',
    '600',
    '1',
    '2024'
]);

fputcsv($output, [
    'Jane Smith',
    'Customer Satisfaction',
    '5',
    '25',
    '100',
    '300',
    '2',
    '2024'
]);

fclose($output);
exit();
?>