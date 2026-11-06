<?php
// The look of the portal in one place. Everything here comes from the shared helpers and classes, so
// a page that looks different from this has its own code to remove. English only: for developers.
$sample = ['id' => 1, 'name' => 'Sample'];
?>
<div class="card page-header-card">
    <?php echo uiPageHeaderOpen('fa-heading', 'Page title: <code>uiPageHeader</code>', 'kitHelpBox'); ?>
    <span class="badge badge-success">Status</span>
    <?php echo uiPageHeaderActions(); ?>
    <button type="button" class="btn btn-secondary btn-icon" title="Refresh"><i class="fas fa-sync-alt"></i></button>
    <button type="button" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Add</button>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('kitHelpBox', 'Help'); ?>Closed until "?" is pressed. In a title only help, refresh, back and full screen are icons; every other button has text. Sizes follow the place: titles 32, forms and windows 36, table rows 28.<?php echo uiHelpBoxClose(); ?>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-swatchbook u-primary"></i> Table: <code>uiTableOpen()</code> / <code>uiTableClose()</code></div></div>
    <?php echo uiTableOpen(['Name', ['label' => 'Extension', 'class' => 'col-hide-mobile'], ['label' => '', 'class' => 'text-right']], ['search' => false]); ?>
        <tr><td>Reception</td><td class="col-hide-mobile">1000</td><td class="text-right"><?php echo uiStatusBadge('active', ['active' => 'success']); ?></td></tr>
        <?php echo uiTableEmptyRow(3, 'An empty table shows this row.'); ?>
    <?php echo uiTableClose(); ?>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-swatchbook u-primary"></i> Tabs: <code>uiTabs()</code></div></div>
    <div style="padding: 0 20px 16px;">
        <?php echo uiTabs([
            'one' => ['label' => 'First', 'icon' => 'fa-inbox'],
            'two' => ['label' => 'With a count', 'icon' => 'fa-list', 'badge' => '12'],
            'three' => ['label' => 'Third', 'icon' => 'fa-cog'],
        ], 'one'); ?>
        <div data-tab-pane="one">First pane.</div>
        <div data-tab-pane="two" hidden>Second pane.</div>
        <div data-tab-pane="three" hidden>Third pane.</div>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-hand-pointer u-primary"></i> Buttons</div></div>
    <div class="u-flex-gap" style="padding: 0 20px 16px; flex-wrap: wrap;">
        <button type="button" class="btn btn-primary"><i class="fas fa-save"></i> Primary</button>
        <button type="button" class="btn btn-secondary">Secondary</button>
        <button type="button" class="btn btn-success">Success</button>
        <button type="button" class="btn btn-warning">Warning</button>
        <button type="button" class="btn btn-danger">Danger</button>
        <button type="button" class="btn btn-primary btn-sm">Small</button>
        <button type="button" class="btn btn-secondary btn-xs"><i class="fas fa-edit"></i></button>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-tag u-primary"></i> Badges and alerts</div></div>
    <div style="padding: 0 20px 16px;">
        <p class="u-flex-gap u-wrap">
            <span class="badge badge-success">Success</span><span class="badge badge-warning">Warning</span><span class="badge badge-danger">Danger</span>
            <span class="badge badge-info">Info</span><span class="badge badge-secondary">Secondary</span>
        </p>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> Saved.</div>
        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Needs attention.</div>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Something went wrong.</div>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-table u-primary"></i> Table</div></div>
    <?php echo uiTableOpen([
        'Name',
        'Status',
        ['label' => 'Actions', 'class' => 'text-right'],
    ]); ?>
            <tr>
                <td>Sample row</td>
                <td><?php echo uiStatusBadge('active', ['active' => 'success'], 'secondary', 'Active'); ?></td>
                <td class="text-right"><?php echo uiRowActions($sample, 'void', 'id', 'none', 'Example only'); ?></td>
            </tr>
            <?php echo uiTableEmptyRow(3, 'The text for an empty table.', 'fa-inbox'); ?>
        <?php echo uiTableClose(); ?>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-edit u-primary"></i> Form and window</div></div>
    <div style="padding: 0 20px 16px;">
        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label">Text</label>
                <input type="text" class="form-control" placeholder="Placeholder">
                <small class="u-hint">A hint under the field.</small>
            </div>
            <div class="form-group">
                <label class="form-label">Choice</label>
                <select class="form-control"><option>One</option><option>Two</option></select>
            </div>
        </div>
        <label class="u-check-label"><input type="checkbox" class="u-accent" checked> A checkbox</label>
        <div class="form-actions">
            <button type="button" class="btn btn-secondary" onclick="UIHelper.openOverlayModal('kitModal')">Open a window</button>
            <button type="button" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="kitModal">
    <div class="modal-card u-maxw-520">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-window-maximize u-primary"></i> Window</h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('kitModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Field</label><input type="text" class="form-control"></div>
            <?php echo uiModalFooter("UIHelper.closeOverlayModal('kitModal')"); ?>
        </div>
    </div>
</div>
