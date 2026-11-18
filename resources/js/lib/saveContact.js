// Cara "Simpan ke Kontak HP" per perangkat. iOS membuka layar "Kontak Baru" langsung dari vCard;
// Android & desktop hanya bisa lewat file .vcf, jadi diberi petunjuk dulu. Peramban dalam aplikasi sering gagal.
const IN_APP = /; wv\)|FBAN|FBAV|FB_IAB|Instagram|Line\/|WhatsApp|TikTok|musical_ly|BytedanceWebview|Snapchat/i

export function contactDevice(ua = '') {
  const ios = /iPhone|iPad|iPod/i.test(ua)
  const platform = ios ? 'ios' : /Android/i.test(ua) ? 'android' : 'desktop'
  return { platform, inApp: IN_APP.test(ua) }
}

/** true = buka vCard langsung tanpa lembar petunjuk. */
export const savesDirectly = (device) => device.platform === 'ios' && !device.inApp
