<?php
require_once __DIR__ . '/approval_workflow.php';
createDefaultScholarshipWorkflow($pdo, (int) $scholarship['ScholarshipID']);
$stageStmt = $pdo->prepare('SELECT * FROM scholarship_workflow_stages WHERE ScholarshipID = ? ORDER BY StageOrder');
$stageStmt->execute([$scholarship['ScholarshipID']]);
$workflowStages = $stageStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<main class="flex-1 p-5 lg:p-10 lg:ml-72 bg-slate-50 min-h-screen">
  <div class="max-w-6xl mx-auto">
    <header class="mb-8 flex flex-col md:flex-row md:items-end justify-between gap-4">
      <div>
        <a href="<?= htmlspecialchars($back_url) ?>" class="text-sm font-bold text-blue-600 hover:text-blue-800">← Back to scholarships</a>
        <p class="mt-5 text-[11px] font-black uppercase tracking-[.18em] text-blue-600">Sponsor-specific configuration</p>
        <h1 class="text-3xl lg:text-4xl font-black text-slate-900 mt-1">Approval Workflow</h1>
        <p class="text-slate-500 mt-2"><span class="font-bold text-slate-700"><?= htmlspecialchars($scholarship['Name']) ?></span> follows the ordered stages below.</p>
      </div>
      <a href="workflow_tasks.php" class="px-5 py-3 rounded-xl bg-slate-900 text-white text-xs font-black uppercase tracking-widest hover:bg-blue-700">Open review tasks</a>
    </header>

    <?php if (isset($_SESSION['success'])): ?><div class="mb-5 p-4 rounded-xl bg-green-50 border border-green-200 text-green-800 font-bold"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?><div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 font-bold"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

    <div class="mb-6 p-5 rounded-2xl bg-blue-50 border border-blue-200 text-sm text-blue-900">
      <strong>Version-safe:</strong> edits apply to applications submitted after you save. Applications already under review retain their original stages, reviewer assignments, comments, and completion history.
    </div>

    <form method="post" action="../actions/process_workflow.php" id="workflowForm">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="scholarship_id" value="<?= (int) $scholarship['ScholarshipID'] ?>">
      <div id="stageList" class="space-y-4">
        <?php foreach ($workflowStages as $stage): ?>
          <section class="workflow-stage bg-white border border-slate-200 rounded-2xl p-5 shadow-sm" draggable="true">
            <div class="flex items-start gap-4">
              <button type="button" class="drag-handle mt-2 text-slate-400 hover:text-slate-800 cursor-move" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></button>
              <div class="flex-1 grid md:grid-cols-2 gap-4">
                <label class="text-xs font-black uppercase tracking-wider text-slate-500">Stage name<input name="stage_name[]" required maxlength="120" value="<?= htmlspecialchars($stage['StageName']) ?>" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold"></label>
                <label class="text-xs font-black uppercase tracking-wider text-slate-500">Stage type<select name="stage_key[]" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold"><?php foreach (WORKFLOW_STAGE_TYPES as $key => $label): ?><option value="<?= $key ?>" <?= $stage['StageKey'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></label>
                <label class="text-xs font-black uppercase tracking-wider text-slate-500">Responsible reviewer<select name="assigned_role[]" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold"><option value="Internal_Admin" <?= $stage['AssignedRole'] === 'Internal_Admin' ? 'selected' : '' ?>>Internal Admin</option><option value="External_Admin" <?= $stage['AssignedRole'] === 'External_Admin' ? 'selected' : '' ?>>External Sponsor / Evaluator</option><option value="Super_Admin" <?= $stage['AssignedRole'] === 'Super_Admin' ? 'selected' : '' ?>>Super Admin</option></select></label>
                <label class="text-xs font-black uppercase tracking-wider text-slate-500">When completed<select name="completion_action[]" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold"><?php foreach (WORKFLOW_COMPLETION_ACTIONS as $key => $label): ?><option value="<?= $key ?>" <?= $stage['CompletionAction'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></label>
                <label class="md:col-span-2 text-xs font-black uppercase tracking-wider text-slate-500">Reviewer instructions<textarea name="instructions[]" rows="2" maxlength="1000" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"><?= htmlspecialchars((string) $stage['Instructions']) ?></textarea></label>
              </div>
              <button type="button" class="remove-stage w-10 h-10 rounded-xl text-red-500 hover:bg-red-50" title="Remove stage"><i class="fas fa-trash"></i></button>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
      <div class="mt-6 flex flex-wrap gap-3">
        <button type="button" id="addStage" class="px-5 py-3 rounded-xl bg-white border border-blue-300 text-blue-700 text-xs font-black uppercase tracking-widest hover:bg-blue-50">+ Add stage</button>
        <button type="submit" class="px-6 py-3 rounded-xl bg-blue-600 text-white text-xs font-black uppercase tracking-widest hover:bg-blue-700">Save workflow</button>
      </div>
    </form>
  </div>
</main>
<template id="stageTemplate"><section class="workflow-stage bg-white border border-slate-200 rounded-2xl p-5 shadow-sm" draggable="true"><div class="flex items-start gap-4"><button type="button" class="drag-handle mt-2 text-slate-400 cursor-move"><i class="fas fa-grip-vertical"></i></button><div class="flex-1 grid md:grid-cols-2 gap-4"><label class="text-xs font-black uppercase tracking-wider text-slate-500">Stage name<input name="stage_name[]" required maxlength="120" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold"></label><label class="text-xs font-black uppercase tracking-wider text-slate-500">Stage type<select name="stage_key[]" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold"><?php foreach (WORKFLOW_STAGE_TYPES as $key => $label): ?><option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></label><label class="text-xs font-black uppercase tracking-wider text-slate-500">Responsible reviewer<select name="assigned_role[]" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold"><option value="Internal_Admin">Internal Admin</option><option value="External_Admin">External Sponsor / Evaluator</option><option value="Super_Admin">Super Admin</option></select></label><label class="text-xs font-black uppercase tracking-wider text-slate-500">When completed<select name="completion_action[]" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold"><?php foreach (WORKFLOW_COMPLETION_ACTIONS as $key => $label): ?><option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></label><label class="md:col-span-2 text-xs font-black uppercase tracking-wider text-slate-500">Reviewer instructions<textarea name="instructions[]" rows="2" maxlength="1000" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></textarea></label></div><button type="button" class="remove-stage w-10 h-10 rounded-xl text-red-500 hover:bg-red-50"><i class="fas fa-trash"></i></button></div></section></template>
<script>
const list=document.getElementById('stageList'),tpl=document.getElementById('stageTemplate');
document.getElementById('addStage').addEventListener('click',()=>{if(list.children.length>=12)return alert('Maximum of 12 stages.');list.append(tpl.content.cloneNode(true));});
list.addEventListener('click',e=>{const b=e.target.closest('.remove-stage');if(b){if(list.children.length===1)return alert('A workflow needs at least one stage.');b.closest('.workflow-stage').remove();}});
let dragged=null;list.addEventListener('dragstart',e=>{dragged=e.target.closest('.workflow-stage');if(!dragged)e.preventDefault();});list.addEventListener('dragover',e=>{e.preventDefault();const t=e.target.closest('.workflow-stage');if(t&&t!==dragged){const r=t.getBoundingClientRect();list.insertBefore(dragged,e.clientY<r.top+r.height/2?t:t.nextSibling);}});list.addEventListener('dragend',()=>dragged=null);
</script>

