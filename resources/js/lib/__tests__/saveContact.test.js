import { test } from 'node:test'
import assert from 'node:assert/strict'
import { contactDevice, savesDirectly } from '../saveContact.js'

const UA = {
  androidChrome: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36',
  samsung: 'Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0 Mobile Safari/537.36',
  androidWebView: 'Mozilla/5.0 (Linux; Android 14; Pixel 8; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0 Mobile Safari/537.36',
  instagram: 'Mozilla/5.0 (Linux; Android 14; wv) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36 Instagram 300.0.0',
  iphoneSafari: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
  iphoneFacebook: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 [FBAN/FBIOS;FBAV/480.0]',
  iphoneLine: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari Line/14.0.0',
  desktop: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
}

test('contactDevice mengenali platform & peramban dalam aplikasi', () => {
  assert.deepEqual(contactDevice(UA.androidChrome), { platform: 'android', inApp: false })
  assert.deepEqual(contactDevice(UA.samsung), { platform: 'android', inApp: false })
  assert.deepEqual(contactDevice(UA.androidWebView), { platform: 'android', inApp: true })
  assert.deepEqual(contactDevice(UA.instagram), { platform: 'android', inApp: true })
  assert.deepEqual(contactDevice(UA.iphoneSafari), { platform: 'ios', inApp: false })
  assert.deepEqual(contactDevice(UA.iphoneFacebook), { platform: 'ios', inApp: true })
  assert.deepEqual(contactDevice(UA.iphoneLine), { platform: 'ios', inApp: true })
  assert.deepEqual(contactDevice(UA.desktop), { platform: 'desktop', inApp: false })
  assert.deepEqual(contactDevice(''), { platform: 'desktop', inApp: false })
})

test('hanya Safari/peramban iOS biasa yang langsung membuka vCard', () => {
  assert.equal(savesDirectly(contactDevice(UA.iphoneSafari)), true)
  for (const ua of [UA.androidChrome, UA.samsung, UA.instagram, UA.iphoneFacebook, UA.desktop]) {
    assert.equal(savesDirectly(contactDevice(ua)), false, ua)
  }
})
