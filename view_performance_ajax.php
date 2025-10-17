<?php
session_start();
require_once 'db.php';
$user_id = $_SESSION['user_id'];

$from = $_GET['from_date'] ?? '';
$to = $_GET['to_date'] ?? '';
$kpi_id = $_GET['kpi_id'] ?? '';

$query = "SELECT up.performance_date, k.kpi_name, up.performance_value 
          FROM user_performance up 
          JOIN kpis k ON up.kpi_id = k.id 
          WHERE up.user_id=$user_id";

if($from) $query .= " AND performance_date >= '$from'";
if($to) $query .= " AND performance_date <= '$to'";
if($kpi_id) $query .= " AND k.id = '$kpi_id'";

$query .= " ORDER BY up.performance_date DESC";
$result = $conn->query($query);

echo '<table>
        <thead>
            <tr>
                <th>Date</th>
                <th>KPI</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>';

if($result->num_rows == 0){
    echo "<tr><td colspan='3' style='text-align:center;'>No data found</td></tr>";
} else {
    while($row = $result->fetch_assoc()){
        echo "<tr>
                <td>{$row['performance_date']}</td>
                <td>{$row['kpi_name']}</td>
                <td>{$row['performance_value']}</td>
              </tr>";
    }
}

echo '</tbody></table>';
