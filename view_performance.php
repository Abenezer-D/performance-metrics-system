<?php
session_start();
require_once 'db.php';
$user_id = $_SESSION['user_id'];
include 'sidebar.php';

// Fetch all KPIs for filter dropdown
$kpi_result = $conn->query("SELECT * FROM kpis");
$kpis = [];
while($row = $kpi_result->fetch_assoc()) $kpis[] = $row;
?>

<div class="main-content">
    <h2>View Previous Performance</h2>

    <!-- Filter Form -->
    <div class="filter-card">
        <form id="filterForm">
            <label>From:</label>
            <input type="date" name="from_date">
            <label>To:</label>
            <input type="date" name="to_date">
            <label>KPI:</label>
            <select name="kpi_id">
                <option value="">All KPIs</option>
                <?php foreach($kpis as $k): ?>
                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['kpi_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Search</button>
        </form>
    </div>

    <!-- Table -->
    <div class="table-card" id="performanceTable">
        <!-- AJAX content will load here -->
    </div>
</div>

<style>
.main-content { margin-left:220px; padding:20px; }
.filter-card {
    background: linear-gradient(135deg, #6a11cb, #2575fc);
    padding: 20px;
    border-radius: 15px;
    color: #fff;
    margin-bottom: 20px;
    max-width: 500px;
}
.filter-card label { display:block; margin-top:10px; font-weight:bold; }
.filter-card input, .filter-card select {
    width:100%;
    padding:10px;
    border-radius:8px;
    border:none;
    margin-bottom:15px;
}
.filter-card button {
    background:#ffb400;
    color:#000;
    padding:10px;
    border:none;
    border-radius:10px;
    font-weight:bold;
    cursor:pointer;
    width:100%;
    transition: background 0.3s;
}
.filter-card button:hover { background:#ffa500; }

.table-card {
    background:#fff;
    padding:20px;
    border-radius:15px;
    box-shadow:0 4px 15px rgba(0,0,0,0.1);
    overflow-x:auto;
}
table {
    width:100%;
    border-collapse:collapse;
    min-width: 600px;
}
thead {
    background: linear-gradient(135deg, #6a11cb, #2575fc);
    color:#fff;
}
th, td {
    padding:12px;
    text-align:left;
    border-bottom:1px solid #ddd;
}
tbody tr:nth-child(even){ background: #f9f9f9; }
tbody tr:hover { background: #e0e0e0; }
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function loadTable() {
    $.ajax({
        url: 'view_performance_ajax.php',
        type: 'GET',
        data: $('#filterForm').serialize(),
        success: function(res){
            $('#performanceTable').html(res);
        },
        error: function(xhr,status,error){
            alert('AJAX Error: '+error);
            console.log(xhr.responseText);
        }
    });
}

$(document).ready(function(){
    loadTable(); // Load table initially

    $('#filterForm').on('submit', function(e){
        e.preventDefault();
        loadTable(); // Load table on filter submit
    });
});
</script>
