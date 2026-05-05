<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

$page_title = 'Settings';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="section">
    <div class="row justify-content-center">
        <div class="col-12 col-xxl-10">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-lg-5">
                    <div class="mb-4">
                        <div class="text-uppercase small text-muted fw-semibold">Administration</div>
                        <h4 class="mb-2">Settings</h4>
                        <p class="text-muted mb-0">Manage threshold rules, document-specific signatory settings, and access settings from separate pages.</p>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-4">
                            <div class="border rounded-3 p-4 h-100">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="fs-3 text-primary"><i class="bi bi-sliders"></i></div>
                                    <div>
                                        <div class="text-uppercase small text-muted fw-semibold">Property</div>
                                        <h5 class="mb-0">Thresholds</h5>
                                    </div>
                                </div>
                                <p class="text-muted small mb-4">Maintain active equipment and semi-expendable threshold values and review threshold history.</p>
                                <a href="<?php echo base_url('modules/settings/thresholds.php'); ?>" class="btn btn-primary w-100">Open Thresholds</a>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="border rounded-3 p-4 h-100">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="fs-3 text-primary"><i class="bi bi-file-earmark-text"></i></div>
                                    <div>
                                        <div class="text-uppercase small text-muted fw-semibold">Document</div>
                                        <h5 class="mb-0">RIS Approver</h5>
                                    </div>
                                </div>
                                <p class="text-muted small mb-4">Set the officer name used in the Approved by block of the RIS form.</p>
                                <a href="<?php echo base_url('modules/settings/ris_approver.php'); ?>" class="btn btn-primary w-100">Open RIS Approver</a>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="border rounded-3 p-4 h-100">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="fs-3 text-primary"><i class="bi bi-person-badge"></i></div>
                                    <div>
                                        <div class="text-uppercase small text-muted fw-semibold">Document</div>
                                        <h5 class="mb-0">University President</h5>
                                    </div>
                                </div>
                                <p class="text-muted small mb-4">Set the University President details used in reports such as RPCPPE.</p>
                                <a href="<?php echo base_url('modules/settings/university_president.php'); ?>" class="btn btn-primary w-100">Open University President</a>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="border rounded-3 p-4 h-100">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="fs-3 text-primary"><i class="bi bi-hdd-stack"></i></div>
                                    <div>
                                        <div class="text-uppercase small text-muted fw-semibold">System</div>
                                        <h5 class="mb-0">Database Tools</h5>
                                    </div>
                                </div>
                                <p class="text-muted small mb-4">Create SQL backups and upload database dumps for restore operations.</p>
                                <a href="<?php echo base_url('modules/settings/database_tools.php'); ?>" class="btn btn-primary w-100">Open Database Tools</a>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="border rounded-3 p-4 h-100">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="fs-3 text-primary"><i class="bi bi-wifi"></i></div>
                                    <div>
                                        <div class="text-uppercase small text-muted fw-semibold">System</div>
                                        <h5 class="mb-0">System Access & Session</h5>
                                    </div>
                                </div>
                                <p class="text-muted small mb-4">Set the network IP or host name used in QR tags and configure idle session timeout.</p>
                                <a href="<?php echo base_url('modules/settings/system_access.php'); ?>" class="btn btn-primary w-100">Open Access & Session</a>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="border rounded-3 p-4 h-100">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="fs-3 text-primary"><i class="bi bi-folder-symlink"></i></div>
                                    <div>
                                        <div class="text-uppercase small text-muted fw-semibold">System</div>
                                        <h5 class="mb-0">Upload Storage</h5>
                                    </div>
                                </div>
                                <p class="text-muted small mb-4">Set the global uploads root folder and copy or move existing files during path changes.</p>
                                <a href="<?php echo base_url('modules/settings/upload_storage.php'); ?>" class="btn btn-primary w-100">Open Upload Storage</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
