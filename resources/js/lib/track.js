// Pelacakan event landing tanpa skrip pihak ketiga: CustomEvent `tapcard:track` + window.dataLayer bila ada
// (mis. Google Tag Manager dipasang belakangan). Nama event: cta_click, whatsapp_click, faq_open, plan_view.
export function track(event, props = {}) {
  if (typeof window === 'undefined') return
  const detail = { event, ...props }
  window.dispatchEvent(new CustomEvent('tapcard:track', { detail }))
  if (Array.isArray(window.dataLayer)) window.dataLayer.push(detail)
}
