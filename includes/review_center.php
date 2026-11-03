<?php
// ✨ EVALUATION CENTER: Workflow Tasks, AI Review, Conflicts, Rubrics and Overrides in one modal on the
// Manage Scholarships page (super_admin/scholarships.php, external_admin/scholarships.php, internal_admin/programs.php).
// Each tab shows the real page in a frame (header.php hides its menus there), so its forms and rules stay in one place.
// Add ?scholarship_id=N to limit a tab to one scholarship.

function reviewCenterTabs(): array {
    $tabs = ['workflow_tasks.php' => '🔀 Workflow Tasks', 'ai_reviews.php' => '🤖 AI Review'];
    if (($_SESSION['role'] ?? '') !== 'Internal_Admin') {
        $tabs += ['evaluation_conflicts.php' => '⚠️ Conflicts', 'scoring_rubrics.php' => '🎯 Rubrics'];
    }
    if (($_SESSION['role'] ?? '') === 'Super_Admin') $tabs['score_overrides.php'] = '✏️ Overrides';
    return $tabs;
}

// Waiting items per tab, using the same limits as the tabs themselves
function reviewCenterCounts(PDO $pdo): array {
    $role = $_SESSION['role'] ?? ''; $me = (int) ($_SESSION['user_id'] ?? 0);
    $counts = [];
    try {
        $sql = "SELECT COUNT(*) FROM application_workflow_progress p JOIN application a ON a.ApplicationID = p.ApplicationID JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
                WHERE p.Status = 'In Progress' AND a.Status NOT IN ('Draft','Withdrawn','Rejected','Disqualified','Waitlisted','Waitlist Offered','Slot Vacated')";
        $params = [];
        if ($role !== 'Super_Admin') { $sql .= ' AND p.AssignedRole = ?'; $params[] = $role; }
        if ($role === 'External_Admin') { $sql .= ' AND s.CreatedBy = ?'; $params[] = $me; }
        $st = $pdo->prepare($sql); $st->execute($params);
        $counts['workflow_tasks.php'] = (int) $st->fetchColumn();

        $sql = "SELECT COUNT(*) FROM ai_document_analyses ai JOIN application a ON a.ApplicationID = ai.ApplicationID JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
                LEFT JOIN users cu ON cu.UserID = s.CreatedBy WHERE ai.ReviewStatus IN ('Pending', 'Inconclusive')";
        $params = [];
        if ($role === 'Internal_Admin') $sql .= " AND (cu.Role IS NULL OR cu.Role IN ('Internal_Admin','Super_Admin'))";
        elseif ($role === 'External_Admin') { $sql .= ' AND s.CreatedBy = ?'; $params[] = $me; }
        $st = $pdo->prepare($sql); $st->execute($params);
        $counts['ai_reviews.php'] = (int) $st->fetchColumn();

        $sql = "SELECT COUNT(*) FROM evaluation_conflict_declarations d JOIN application a ON a.ApplicationID = d.ApplicationID JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
                WHERE d.ConflictStatus = 'Conflict Disclosed' AND d.ResolutionStatus = 'Pending Reassignment'";
        $params = [];
        if ($role === 'External_Admin') { $sql .= ' AND s.CreatedBy = ?'; $params[] = $me; }
        $st = $pdo->prepare($sql); $st->execute($params);
        $counts['evaluation_conflicts.php'] = (int) $st->fetchColumn();
    } catch (PDOException $e) {
        error_log('Evaluation Center counts: ' . $e->getMessage());
    }
    return array_intersect_key($counts, reviewCenterTabs());
}

// The page-level button: every scholarship
function reviewCenterButton(PDO $pdo): void {
    $waiting = array_sum(reviewCenterCounts($pdo)); ?>
    <button type="button" onclick="openReviewCenter()" class="relative bg-white border border-violet-200 text-violet-700 px-5 py-2.5 rounded-xl font-black text-[12px] uppercase tracking-widest hover:bg-violet-600 hover:text-white transition-all shadow-sm whitespace-nowrap">
        ⚖️ Evaluation Center
        <?php if ($waiting > 0): ?><span class="ml-1 inline-block min-w-[20px] px-1.5 py-0.5 rounded-full bg-amber-500 text-white text-[10px] text-center" title="<?= $waiting ?> waiting"><?= $waiting > 999 ? '999+' : $waiting ?></span><?php endif; ?>
    </button>
<?php }

// A scholarship card's button: just that scholarship
function reviewCardButton(int $scholarship_id, string $extra_class = ''): void { ?>
    <button type="button" onclick="openReviewCenter(<?= $scholarship_id ?>)" title="Workflow tasks, AI review and scoring for this scholarship" class="<?= htmlspecialchars($extra_class) ?> bg-violet-50 text-violet-700 border border-violet-200 hover:bg-violet-600 hover:text-white px-5 py-2.5 rounded-xl font-black text-[11px] uppercase tracking-widest transition-all shadow-sm">Evaluate ⚖️</button>
<?php }

function reviewCenterModal(PDO $pdo): void {
    $role = $_SESSION['role'] ?? '';
    $sql = "SELECT s.ScholarshipID, s.Name FROM scholarship s LEFT JOIN users cu ON cu.UserID = s.CreatedBy";
    $params = [];
    if ($role === 'Internal_Admin') $sql .= " WHERE cu.Role IS NULL OR cu.Role IN ('Internal_Admin','Super_Admin')";
    elseif ($role === 'External_Admin') { $sql .= ' WHERE s.CreatedBy = ?'; $params[] = (int) $_SESSION['user_id']; }
    $st = $pdo->prepare($sql . ' ORDER BY s.Name'); $st->execute($params);
    $scholarships = $st->fetchAll(PDO::FETCH_ASSOC);
    $tabs = reviewCenterTabs();
    $counts = reviewCenterCounts($pdo);
    ?>
    <div id="reviewCenter" class="fixed inset-0 z-[100010] hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm p-2 sm:p-5" role="dialog" aria-modal="true" aria-labelledby="reviewCenterTitle">
        <div class="bg-white w-full max-w-6xl h-full max-h-[94vh] rounded-[1.5rem] shadow-2xl flex flex-col overflow-hidden">
            <div class="p-4 lg:px-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center gap-3 shrink-0">
                <div class="flex-1 min-w-0">
                    <h3 id="reviewCenterTitle" class="text-lg lg:text-xl font-black text-slate-900 tracking-tight">Evaluation Center</h3>
                    <p class="text-xs font-medium text-slate-500">Review tasks, AI suggestions and scoring without leaving this page.</p>
                </div>
                <select id="reviewCenterScope" onchange="reviewCenterSet(null, parseInt(this.value, 10) || 0)" aria-label="Scholarship" class="min-w-0 md:w-72 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-700 outline-none cursor-pointer">
                    <option value="0">All scholarships</option>
                    <?php foreach ($scholarships as $s): ?><option value="<?= (int) $s['ScholarshipID'] ?>"><?= htmlspecialchars($s['Name']) ?></option><?php endforeach; ?>
                </select>
                <button type="button" onclick="closeReviewCenter()" aria-label="Close" class="absolute md:static top-4 right-4 w-9 h-9 shrink-0 rounded-full bg-slate-100 text-slate-600 hover:bg-red-500 hover:text-white font-bold transition-colors">&times;</button>
            </div>
            <div class="no-scrollbar flex gap-1.5 overflow-x-auto px-4 lg:px-6 py-2.5 border-b border-slate-100 bg-slate-50/60 shrink-0" role="tablist" style="scrollbar-width: none;">
                <?php foreach ($tabs as $page => $label): ?>
                    <button type="button" role="tab" data-review-tab="<?= htmlspecialchars($page) ?>" onclick="reviewCenterSet('<?= htmlspecialchars($page) ?>')" class="shrink-0 px-3.5 py-2 rounded-lg text-[11px] font-black whitespace-nowrap transition-colors">
                        <?= $label ?><?php if (!empty($counts[$page])): ?> <span class="ml-0.5 opacity-80">(<?= number_format($counts[$page]) ?>)</span><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="relative flex-1 min-h-0 bg-slate-50">
                <div id="reviewCenterLoading" class="absolute inset-0 flex items-center justify-center text-sm font-bold text-slate-400">Loading…</div>
                <iframe id="reviewCenterFrame" title="Evaluation Center" class="relative w-full h-full border-0 bg-transparent"></iframe>
            </div>
        </div>
    </div>
    <script>
    (function () {
        const modal = document.getElementById('reviewCenter'), frame = document.getElementById('reviewCenterFrame');
        const loading = document.getElementById('reviewCenterLoading'), scope = document.getElementById('reviewCenterScope');
        const firstTab = <?= json_encode(array_key_first($tabs)) ?>;
        let tab = firstTab, scholarship = 0;

        function show() {
            document.querySelectorAll('[data-review-tab]').forEach(b => {
                const on = b.dataset.reviewTab === tab;
                b.setAttribute('aria-selected', on ? 'true' : 'false');
                b.classList.toggle('bg-slate-900', on); b.classList.toggle('text-white', on);
                b.classList.toggle('bg-white', !on); b.classList.toggle('text-slate-600', !on);
            });
            scope.value = String(scholarship);
            loading.classList.remove('hidden');
            frame.style.visibility = 'hidden';
            frame.src = tab + (scholarship ? '?scholarship_id=' + scholarship : '');
        }
        frame.addEventListener('load', () => { if (frame.getAttribute('src')) { loading.classList.add('hidden'); frame.style.visibility = 'visible'; } });

        window.reviewCenterSet = function (newTab, newScholarship) {
            if (newTab) tab = newTab;
            if (newScholarship !== undefined) scholarship = newScholarship;
            show();
        };
        window.openReviewCenter = function (scholarshipId) {
            scholarship = scholarshipId || 0;
            tab = scholarshipId && tab === firstTab ? firstTab : tab;
            modal.classList.remove('hidden'); modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
            show();
        };
        window.closeReviewCenter = function () {
            modal.classList.add('hidden'); modal.classList.remove('flex');
            document.body.style.overflow = '';
            frame.removeAttribute('src');
        };
        modal.addEventListener('click', e => { if (e.target === modal) closeReviewCenter(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeReviewCenter(); });
    })();
    </script>
<?php }
