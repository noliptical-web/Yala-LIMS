<nav class="navbar navbar-expand-lg navbar-dark bg-primary px-3 mb-4">
    <a class="navbar-brand" href="dashboard.php">🏥 Yala Hospital LIMS</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav me-auto">
            <li class="nav-item"><a class="nav-link" href="dashboard.php">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="add_patient.php">Reception</a></li>
            <li class="nav-item"><a class="nav-link" href="request_test.php">Order Tests</a></li>
        </ul>
        <span class="text-white me-3">
            User: <?php echo isset($_SESSION['username']) ? $_SESSION['username'] : 'Guest'; ?>
        </span>
        <a href="logout.php" class="btn btn-sm btn-light text-primary">Logout</a>
    </div>
</nav>