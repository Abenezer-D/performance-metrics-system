<?php
include 'config.php';

echo "<h2>Database Structure Debug</h2>";

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// List all tables
$tables_result = $conn->query("SHOW TABLES");
echo "<h3>Available Tables:</h3>";
echo "<ul>";
while ($row = $tables_result->fetch_array()) {
    $table_name = $row[0];
    echo "<li><strong>" . $table_name . "</strong>";
    
    // Show table structure
    $structure = $conn->query("DESCRIBE " . $table_name);
    echo "<ul><li>Structure: ";
    while ($col = $structure->fetch_assoc()) {
        echo $col['Field'] . " (" . $col['Type'] . "), ";
    }
    echo "</li>";
    
    // Show sample data
    $data = $conn->query("SELECT * FROM " . $table_name . " LIMIT 5");
    echo "<li>Sample data (" . $data->num_rows . " rows):<br>";
    if ($data->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; margin: 5px 0;'>";
        // Header
        echo "<tr>";
        while ($col = $data->fetch_field()) {
            echo "<th style='padding: 3px;'>" . $col->name . "</th>";
        }
        echo "</tr>";
        $data->data_seek(0); // Reset pointer
        
        // Data rows
        while ($row_data = $data->fetch_assoc()) {
            echo "<tr>";
            foreach ($row_data as $value) {
                echo "<td style='padding: 3px;'>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No data found";
    }
    echo "</li></ul>";
    echo "</li>";
}
echo "</ul>";

// Test the main query
echo "<h3>Testing Main Query:</h3>";
$test_query = "
    SELECT 
        u.username AS staff_name,
        u.position,
        us.branch,
        k.kpi_name,
        COUNT(us.id) AS submission_count
    FROM user_submissions us
    JOIN users u ON us.user_id = u.id
    JOIN kpis k ON us.kpi_id = k.id
    WHERE us.status = 'approved' 
    AND (u.position != 'manager' OR u.position IS NULL)
    GROUP BY u.username, u.position, us.branch, k.kpi_name
    LIMIT 10
";

$test_result = $conn->query($test_query);
if ($test_result === false) {
    echo "<p style='color: red;'>Query Error: " . $conn->error . "</p>";
} else {
    echo "<p>Query returned " . $test_result->num_rows . " rows</p>";
    if ($test_result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Staff Name</th><th>Position</th><th>Branch</th><th>KPI</th><th>Submissions</th></tr>";
        while ($row = $test_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['staff_name'] . "</td>";
            echo "<td>" . $row['position'] . "</td>";
            echo "<td>" . $row['branch'] . "</td>";
            echo "<td>" . $row['kpi_name'] . "</td>";
            echo "<td>" . $row['submission_count'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}
?>