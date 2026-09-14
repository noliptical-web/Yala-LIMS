</div><!-- /.container.main-content -->

<style>
.yala-footer-light {
    background: #ffffff;
    border-top: 3px solid #2563eb;
    color: #475569;
    font-size: 0.88rem;
    box-shadow: 0 -6px 24px rgba(15, 23, 42, 0.06);
}
.yala-footer-light .footer-title {
    color: #0f172a;
    font-weight: 700;
    letter-spacing: 0.02em;
}
.yala-footer-light .text-medical {
    color: #2563eb !important;
}
.yala-footer-light .footer-lead {
    color: #64748b;
    line-height: 1.55;
    font-size: 0.84rem;
}
.yala-footer-light .footer-panel {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
    transition: all 0.2s ease;
}
.yala-footer-light .footer-panel:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}
.yala-footer-light .footer-link {
    color: #475569;
    text-decoration: none;
    transition: color 0.2s ease, transform 0.2s ease;
    display: inline-flex;
    align-items: center;
    font-size: 0.84rem;
}
.yala-footer-light .footer-link:hover {
    color: #2563eb;
    transform: translateX(3px);
}
.yala-footer-light .badge-facility {
    background: #dbeafe;
    color: #1e40af;
    border: 1px solid #bfdbfe;
    font-weight: 600;
}
.yala-footer-light .badge-ecitizen {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
    font-weight: 700;
}
.yala-footer-light .badge-reg {
    background: #2563eb;
    color: #ffffff;
    font-weight: 600;
}
.yala-footer-light .badge-std {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #cbd5e1;
    font-weight: 500;
}
.yala-footer-light hr {
    border-color: #e2e8f0;
    opacity: 0.8;
}
</style>

<footer class="yala-footer-light mt-auto pt-5 pb-3">
    <div class="container">
        <div class="row g-4">

            <!-- Col 1: Hospital & Catchment Profile -->
            <div class="col-lg-3 col-md-6">
                <div class="d-flex align-items-center mb-3">
                    <?php if(file_exists('logo.png')): ?>
                        <img src="logo.png" height="38" class="me-2 rounded shadow-sm border p-1 bg-white" alt="Yala Logo">
                    <?php else: ?>
                        <i class="fa-solid fa-hospital fa-2x text-medical me-2"></i>
                    <?php endif; ?>
                    <div>
                        <h6 class="footer-title mb-0" style="font-size:0.95rem;">YALA SUB-COUNTY HOSPITAL</h6>
                        <span class="badge badge-facility text-uppercase" style="font-size:0.65rem;">MOH Level 4 Facility</span>
                    </div>
                </div>
                <p class="footer-lead mb-2">
                    A Ministry of Health Level 4 public healthcare institution serving the Gem Sub-County and Siaya County communities along the Kisumu–Busia highway corridor.
                </p>
                <div class="footer-lead mb-3" style="font-size:0.82rem;">
                    <div class="mb-1"><i class="fa-solid fa-location-dot text-medical me-2"></i>Yala, Gem Sub-County, Siaya County</div>
                    <div class="mb-1"><i class="fa-solid fa-phone text-medical me-2"></i>Casualty / Lab: +254 (0) 57 250522</div>
                    <div><i class="fa-solid fa-envelope text-medical me-2"></i>yala.hospital@health.go.ke</div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge badge-std small py-1 px-2">
                        <i class="fa-solid fa-shield-virus me-1 text-medical"></i>ISO 15189:2022
                    </span>
                    <span class="badge badge-std small py-1 px-2">
                        <i class="fa-solid fa-file-waveform me-1 text-medical"></i>MOH 204 &bull; 706
                    </span>
                </div>
            </div>

            <!-- Col 2: Academic Project & Developer Credentials -->
            <div class="col-lg-3 col-md-6">
                <div class="d-flex align-items-center mb-3">
                    <i class="fa-solid fa-graduation-cap text-medical fa-xl me-2"></i>
                    <h6 class="text-uppercase footer-title mb-0" style="font-size:0.95rem;">Academic Project</h6>
                </div>
                <p class="footer-lead mb-1">
                    <strong class="text-dark">Project Title:</strong><br>
                    <em>Development of a Web-Based LIMS: A Case of Yala Sub-County Hospital</em>
                </p>
                <hr class="my-2">
                <div class="footer-lead mb-1">
                    <i class="fa-solid fa-user-gear text-medical me-2"></i>
                    <strong class="text-dark">Developer:</strong> <span class="text-dark fw-semibold">Owuor Collins</span>
                </div>
                <div class="footer-lead mb-1">
                    <i class="fa-solid fa-id-badge text-medical me-2"></i>
                    <strong class="text-dark">Reg. No:</strong> <span class="badge badge-reg">SCCI/01227/2022</span>
                </div>
                <div class="footer-lead mb-1">
                    <i class="fa-solid fa-user-tie text-medical me-2"></i>
                    <strong class="text-dark">Supervisors:</strong> Dr. Edwin Ngwawe &amp; Mr. Peter Maina Kariuki
                </div>
                <div class="footer-lead mb-0">
                    <i class="fa-solid fa-university text-medical me-2"></i>
                    Technical University of Kenya (TUK) &bull; SCIT
                </div>
            </div>

            <!-- Col 3: Government Cashless & Standards -->
            <div class="col-lg-3 col-md-6">
                <div class="d-flex align-items-center mb-3">
                    <i class="fa-solid fa-landmark-flag text-medical fa-xl me-2"></i>
                    <h6 class="text-uppercase footer-title mb-0" style="font-size:0.95rem;">eCitizen &amp; Standards</h6>
                </div>
                <div class="footer-panel mb-2">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="small fw-bold text-dark">
                            <i class="fa-solid fa-mobile-screen-button me-1 text-medical"></i>eCitizen Cashless
                        </span>
                        <span class="badge badge-ecitizen" style="font-size:0.65rem;">Paybill 222222</span>
                    </div>
                    <p class="footer-lead mb-0" style="font-size:0.75rem;">
                        Compliant with Kenya Gazette Notice No. 16008 &amp; PFM Act 2012. Direct remittance to Siaya County Revenue Fund.
                    </p>
                </div>

                <div class="mt-2">
                    <h6 class="text-uppercase footer-title mb-2" style="font-size:0.75rem; color:#64748b;">Source Repository</h6>
                    <a href="https://github.com/noliptical-web/Yala-LIMS" target="_blank" class="footer-panel w-100 text-start d-flex align-items-center justify-content-between text-decoration-none py-2 px-3">
                        <span class="text-dark small fw-semibold"><i class="fa-brands fa-github fa-lg me-2 text-dark"></i>noliptical-web/Yala-LIMS</span>
                        <i class="fa-solid fa-arrow-up-right-from-square small text-medical"></i>
                    </a>
                </div>

                <div class="mt-2 d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary w-100 py-1" style="font-size:0.75rem;" data-bs-toggle="modal" data-bs-target="#aboutLimsModal">
                        <i class="fa-solid fa-circle-info me-1"></i>About System
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 py-1" style="font-size:0.75rem;" data-bs-toggle="modal" data-bs-target="#shortcutsModal">
                        <i class="fa-solid fa-keyboard me-1"></i>Shortcuts
                    </button>
                </div>
            </div>

            <!-- Col 4: Quick Navigation & Live Session Status -->
            <div class="col-lg-3 col-md-6">
                <div class="d-flex align-items-center mb-3">
                    <i class="fa-solid fa-compass text-medical fa-xl me-2"></i>
                    <h6 class="text-uppercase footer-title mb-0" style="font-size:0.95rem;">System &amp; Session</h6>
                </div>

                <div class="footer-panel mb-2">
                    <?php if(isset($_SESSION['username'])): ?>
                        <div class="small text-dark mb-1">
                            <i class="fa-solid fa-user-circle text-medical me-1"></i>Session:
                            <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                            <span class="badge badge-facility ms-1"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Staff'); ?></span>
                        </div>
                        <div class="footer-lead" style="font-size:0.75rem;">
                            <i class="fa-solid fa-circle text-success me-1" style="font-size:0.6rem;"></i>Database: <strong class="text-dark">yala_lims_db</strong> (Connected)
                        </div>
                    <?php else: ?>
                        <div class="footer-lead">
                            <i class="fa-solid fa-lock text-secondary me-1"></i>Unauthenticated Session
                        </div>
                    <?php endif; ?>
                </div>

                <ul class="list-unstyled small mb-0">
                    <li class="mb-1">
                        <a href="dashboard.php" class="footer-link">
                            <i class="fa-solid fa-gauge me-2 text-medical"></i>Executive Dashboard
                        </a>
                    </li>
                    <li class="mb-1">
                        <a href="notifications.php" class="footer-link">
                            <i class="fa-solid fa-bell me-2 text-medical"></i>Clinical Notifications
                        </a>
                    </li>
                    <li class="mb-1">
                        <a href="blood_bank.php" class="footer-link">
                            <i class="fa-solid fa-droplet me-2 text-medical"></i>Blood Bank &amp; Transfusion
                        </a>
                    </li>
                    <li class="mb-1">
                        <a href="sample_rejection.php" class="footer-link">
                            <i class="fa-solid fa-vial-circle-check me-2 text-medical"></i>Specimen QA &amp; Rejections
                        </a>
                    </li>
                    <li class="mb-1">
                        <a href="qc_log.php" class="footer-link">
                            <i class="fa-solid fa-temperature-half me-2 text-medical"></i>ISO 15189 QC &amp; Temp Logs
                        </a>
                    </li>
                    <?php if(isset($_SESSION['loggedin'])): ?>
                    <li class="mt-2">
                        <a href="logout.php" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size:0.75rem;">
                            <i class="fa-solid fa-power-off me-1"></i>Secure Sign Out
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

        </div>

        <hr class="my-4">

        <!-- Bottom Copyright Row -->
        <div class="row align-items-center g-2">
            <div class="col-md-6 text-center text-md-start small text-muted">
                &copy; <?php echo date('Y'); ?> <strong class="text-dark">Yala Sub-County Hospital LIMS</strong> &bull; County Government of Siaya.<br class="d-sm-none">
                Final Year B.Tech IT Project by <strong class="text-dark">Owuor Collins</strong> (<span class="text-medical">SCCI/01227/2022</span>), TUK.
            </div>
            <div class="col-md-6 text-center text-md-end small text-muted">
                <span class="me-3"><i class="fa-solid fa-shield-halved text-success me-1"></i>256-Bit SSL Intranet</span>
                <span class="me-3"><i class="fa-solid fa-code-branch text-primary me-1"></i>v2.4 (Level 4 Release)</span>
                <a href="https://github.com/noliptical-web/Yala-LIMS" target="_blank" class="text-dark text-decoration-none fw-semibold">
                    <i class="fa-brands fa-github me-1"></i>GitHub
                </a>
            </div>
        </div>
    </div>
</footer>

<!-- ABOUT LIMS MODAL -->
<div class="modal fade" id="aboutLimsModal" tabindex="-1" aria-labelledby="aboutLimsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="aboutLimsModalLabel">
                    <i class="fa-solid fa-hospital-user me-2"></i>About Yala Sub-County Hospital LIMS
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-4 mb-4">
                    <div class="col-md-7">
                        <h6 class="fw-bold text-dark">System Overview</h6>
                        <p class="small text-muted">
                            The <strong>Yala Sub-County Hospital Laboratory Information Management System (LIMS)</strong> is a specialized healthcare informatics platform built to eliminate paper bottlenecks, prevent specimen rejections, enforce laboratory quality compliance (ISO 15189:2022), and integrate digital public finance governance (eCitizen Paybill 222222) within Siaya County, Kenya.
                        </p>
                        <h6 class="fw-bold text-dark mt-3">Core Modules &amp; Capabilities</h6>
                        <ul class="small text-muted ps-3 mb-0">
                            <li><strong>MOH 204 Outpatient Flow:</strong> Automated routing slip with Code128 barcode and clinical allergy flags.</li>
                            <li><strong>Clinical Diagnostic Workbench:</strong> Real-time panic value alerting (Hb &lt; 6.0 g/dL, Malaria +++) and thermal tube label generation.</li>
                            <li><strong>Blood Bank Management:</strong> ABO/Rh crossmatch safety checks, TTI screening, and universal donor inventory alerts.</li>
                            <li><strong>eCitizen Cashless Settlement:</strong> Paybill 222222 direct receipting under Gazette Notice No. 16008 &amp; PFM Act 2012.</li>
                            <li><strong>MOH 706 Reports &amp; Backups:</strong> Monthly laboratory statistical registers and scheduled database backups.</li>
                        </ul>
                    </div>
                    <div class="col-md-5 bg-light p-3 rounded border">
                        <h6 class="fw-bold text-primary mb-2"><i class="fa-solid fa-user-graduate me-1"></i>Project Defense Details</h6>
                        <dl class="row small mb-0">
                            <dt class="col-5 text-muted">Developer:</dt>
                            <dd class="col-7 fw-bold text-dark">Owuor Collins</dd>

                            <dt class="col-5 text-muted">Reg. No:</dt>
                            <dd class="col-7"><span class="badge bg-primary">SCCI/01227/2022</span></dd>

                            <dt class="col-5 text-muted">Degree:</dt>
                            <dd class="col-7">B.Tech (Information Technology)</dd>

                            <dt class="col-5 text-muted">Institution:</dt>
                            <dd class="col-7">The Technical University of Kenya</dd>

                            <dt class="col-5 text-muted">Faculty:</dt>
                            <dd class="col-7">Applied Sciences &amp; Technology (FAST)</dd>

                            <dt class="col-5 text-muted">School:</dt>
                            <dd class="col-7">Computing &amp; Information Tech (SCIT)</dd>

                            <dt class="col-5 text-muted">Supervisors:</dt>
                            <dd class="col-7">Dr. Edwin Ngwawe<br>Mr. Peter Maina Kariuki</dd>

                            <dt class="col-5 text-muted">GitHub:</dt>
                            <dd class="col-7"><a href="https://github.com/noliptical-web/Yala-LIMS" target="_blank" class="text-decoration-none">noliptical-web</a></dd>
                        </dl>
                    </div>
                </div>
                <div class="alert alert-info py-2 px-3 small mb-0">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    <strong>Facility Level:</strong> Ministry of Health Level 4 Hospital &bull; Yala, Gem Sub-County, Siaya County, Kenya.
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <a href="https://github.com/noliptical-web/Yala-LIMS" target="_blank" class="btn btn-sm btn-dark me-auto">
                    <i class="fa-brands fa-github me-1"></i>Repository
                </a>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- KEYBOARD SHORTCUTS MODAL -->
<div class="modal fade" id="shortcutsModal" tabindex="-1" aria-labelledby="shortcutsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold" id="shortcutsModalLabel">
                    <i class="fa-solid fa-keyboard text-warning me-2"></i>Quick Navigation Shortcuts
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle small mb-0">
                        <thead class="table-light">
                            <tr><th>Shortcut</th><th>Action</th><th>Target Page</th></tr>
                        </thead>
                        <tbody>
                            <tr><td><kbd>Alt</kbd> + <kbd>D</kbd></td><td>Go to Dashboard</td><td><code>dashboard.php</code></td></tr>
                            <tr><td><kbd>Alt</kbd> + <kbd>N</kbd></td><td>Hospital Notifications</td><td><code>notifications.php</code></td></tr>
                            <tr><td><kbd>Alt</kbd> + <kbd>P</kbd></td><td>New Patient Registration</td><td><code>add_patient.php</code></td></tr>
                            <tr><td><kbd>Alt</kbd> + <kbd>B</kbd></td><td>Blood Bank Monitor</td><td><code>blood_bank.php</code></td></tr>
                            <tr><td><kbd>Alt</kbd> + <kbd>R</kbd></td><td>Specimen QA &amp; Rejections</td><td><code>sample_rejection.php</code></td></tr>
                            <tr><td><kbd>Alt</kbd> + <kbd>L</kbd></td><td>Secure Logout</td><td><code>logout.php</code></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Keyboard navigation shortcuts
document.addEventListener('keydown', function(e) {
    if (e.altKey) {
        const key = e.key.toLowerCase();
        if (key === 'd') { window.location.href = 'dashboard.php'; }
        else if (key === 'n') { window.location.href = 'notifications.php'; }
        else if (key === 'p') { window.location.href = 'add_patient.php'; }
        else if (key === 'b') { window.location.href = 'blood_bank.php'; }
        else if (key === 'r') { window.location.href = 'sample_rejection.php'; }
        else if (key === 'l') { window.location.href = 'logout.php'; }
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
