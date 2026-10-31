// Ikon lucide untuk layer `glyph` di kartu & lanyard — daftar sama dengan DesignNormalizer::GLYPHS (server menolak nama lain).
import {
  AtSign, Award, Briefcase, Building2, Calendar, Camera, Check, Clock, Code, Coffee, Crown, Facebook, Gem, Github, Globe, GraduationCap,
  Heart, House, Instagram, Landmark, Leaf, Lightbulb, Linkedin, Mail, MapPin, MessageCircle, Music2, Palette, PenTool, Phone, QrCode,
  Rocket, Scale, ScanLine, Send, ShieldCheck, ShoppingBag, Sparkles, Star, Stethoscope, Store, Twitter, User, Users, Wallet, Wifi, Youtube, Zap,
} from 'lucide-vue-next'

export const GLYPHS = {
  phone: Phone, mail: Mail, globe: Globe, 'map-pin': MapPin, 'message-circle': MessageCircle, send: Send, instagram: Instagram,
  linkedin: Linkedin, facebook: Facebook, youtube: Youtube, twitter: Twitter, github: Github, 'at-sign': AtSign, 'building-2': Building2,
  briefcase: Briefcase, user: User, users: Users, calendar: Calendar, clock: Clock, star: Star, heart: Heart, award: Award,
  'shield-check': ShieldCheck, check: Check, leaf: Leaf, coffee: Coffee, camera: Camera, 'music-2': Music2, 'shopping-bag': ShoppingBag,
  store: Store, wallet: Wallet, landmark: Landmark, 'qr-code': QrCode, 'scan-line': ScanLine, wifi: Wifi, zap: Zap, sparkles: Sparkles,
  gem: Gem, crown: Crown, rocket: Rocket, lightbulb: Lightbulb, palette: Palette, 'pen-tool': PenTool, code: Code, stethoscope: Stethoscope,
  'graduation-cap': GraduationCap, scale: Scale, home: House,
}
export const glyph = (name) => GLYPHS[name] ?? Star
