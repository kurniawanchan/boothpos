<?php

return [
    'material_required_for_material_line' => 'A material must be selected for a material-type line.',
    'description_required_for_service_line' => 'A description is required for a service-type line.',
    'items_locked_after_draft' => 'Line items can only be changed while the purchase order is in Draft status.',
    'only_draft_deletable' => 'Only draft purchase orders can be deleted. Cancel it instead.',
    'invalid_status_transition' => 'Cannot change status from :from to :to.',
    'payment_requires_received' => 'A payment can only be recorded after the purchase order has been received.',
    'payment_blocks_delete' => 'This purchase order has recorded payments and cannot be deleted.',
    'bom_blocks_delete' => 'This purchase order is used by a BOM and cannot be deleted.',
];
