<?php
defined('ABSPATH') or exit;
?>

<div class="wdp-row wdp-exlcude-filter-item" data-index="{excludeId}">
    <div class="wdp-column wdp-filter-field-type wdp-filter-exclude-type">
        <select name="rule[filters][{filterId}][excludes][{excludeId}][type]">
            <?php foreach ($product_filter_type_list as $value => $title): ?>
                <option value="<?php echo esc_attr($value) ?>" <?php echo $default_filter === $value ? 'selected' : '' ?>><?php echo esc_html($title) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="wdp-column wdp-filter-exlclude-value" style="flex: 1">
    </div>

    <div class="wdp-column wdp-filter-exlclude-remove" style="max-width: 30px; margin: 0;">
        <div class="wdp-btn-remove-handle">
            <span class="dashicons dashicons-no-alt"></span>
        </div>
    </div>
</div>