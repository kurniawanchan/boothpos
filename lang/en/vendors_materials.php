<?php

return [
    'vendor_delete_has_prices' => 'This vendor still has registered material prices and cannot be deleted.',
    'material_delete_has_vendor_prices' => 'This material still has registered vendor prices and cannot be deleted.',
    'material_delete_used_in_bom' => 'This material is still used in a product variant\'s BOM and cannot be deleted.',
    'not_authorized_vendor_prices' => 'Only owner/admin/inventory can manage vendor prices.',
    'not_authorized_bom_manage' => 'Only owner/admin/inventory can manage the BOM.',
    'not_authorized_bom_view' => 'Only owner/admin/inventory can view the BOM.',
    'not_authorized_cost_breakdown' => 'Only owner/admin/inventory can view the cost breakdown.',
    'vendor_price_already_exists' => 'This vendor already has a price for this material. Update it via the update endpoint instead.',
    'bom_line_already_exists' => 'This material is already in this variant\'s BOM. Update the quantity via the update endpoint instead.',
    'insufficient_material_stock' => 'Insufficient stock for material :material (available: :available).',
    // 087 — modifier option materials
    'material_delete_used_by_modifier' => 'This material is still used by a modifier option (:usage). Remove it from the modifier group first.',
    'option_material_not_found' => 'Material not found.',
    'option_material_inactive' => 'Material :material is inactive or deleted; choose an active material.',
    'option_material_duplicate' => 'The same material cannot be chosen twice for one option.',
    'option_material_quantity' => 'Material quantity must be greater than 0.',
];
