// Contract 17B — block factories and the few rules about blocks the UI itself
// has to know. BlockSchema (server) remains the only authority; these produce
// shapes it accepts.

import { uuid } from './dom';

export const SINGLE = ['product_list', 'signature'];

const FACTORIES = {
    text: () => ({ type: 'text', data: { align: 'left', runs: [] } }),
    heading: () => ({ type: 'heading', data: { level: 2, align: 'left', runs: [] } }),
    image: () => ({ type: 'image', data: { catalog_image_uid: '', alt: '', width_pct: 100 } }),
    divider: () => ({ type: 'divider', data: {} }),
    spacer: () => ({ type: 'spacer', data: { height: 24 } }),
    page_break: () => ({ type: 'page_break', data: {} }),
    section: () => ({ type: 'section', data: { title: '' } }),
    business_details: () => ({ type: 'business_details', data: { show: ['name', 'phone', 'email', 'website'] } }),
    product: () => ({ type: 'product_list', data: { show_description: true, show_quantity: true } }),
    custom_line: () => ({ type: 'product_list', data: { show_description: true, show_quantity: true } }),
    payment_terms: () => ({ type: 'payment_terms', data: {} }),
    signature: () => ({ type: 'signature', data: { label: 'Signature' } }),
    contact_name: () => ({ type: 'text', data: { align: 'left', runs: [{ merge: 'contact.full_name' }] } }),
    contact_email: () => ({ type: 'text', data: { align: 'left', runs: [{ merge: 'contact.email' }] } }),
};

export function newBlock(toolId) {
    const make = FACTORIES[toolId];

    return make ? { id: uuid(), ...make() } : null;
}

export function cloneBlock(block) {
    return { id: uuid(), type: block.type, data: JSON.parse(JSON.stringify(block.data || {})) };
}

/**
 * An image block with no image chosen yet cannot be saved (the server requires
 * one of the Business's catalog images). It stays on the canvas as a
 * placeholder and is simply left out of the save until an image is picked.
 */
export function isSavable(block) {
    return !(block.type === 'image' && !(block.data && block.data.catalog_image_uid));
}

export function savePayload(blocks) {
    return blocks.filter(isSavable);
}

export const TYPE_LABELS = {
    text: 'Text',
    heading: 'Heading',
    image: 'Image',
    divider: 'Divider',
    spacer: 'Spacer',
    page_break: 'Page break',
    section: 'Section',
    business_details: 'Business details',
    product_list: 'Pricing',
    payment_terms: 'Payment terms',
    signature: 'Signature',
};
