// Ikon jenis kontak — hanya lucide (merek yang tidak ada di lucide memakai ikon umum + nama mereknya).
// Kunci = kolom `contact_types.icon`.
import {
  AtSign, BookOpen, Briefcase, CalendarCheck, Clock, Dribbble, Facebook, FileText, Github, Globe, Headphones, Instagram, Landmark, Link,
  Linkedin, Mail, MapPin, MessageCircle, MessagesSquare, Music2, Palette, Phone, Pin, Podcast, ScanQrCode, Send, ShoppingBag, Star, Store,
  Twitter, Wallet, Youtube,
} from 'lucide-vue-next'

export const CONTACT_ICONS = {
  AtSign, BookOpen, Briefcase, CalendarCheck, Clock, Dribbble, Facebook, FileText, Github, Globe, Headphones, Instagram, Landmark, Link,
  Linkedin, Mail, MapPin, MessageCircle, MessagesSquare, Music2, Palette, Phone, Pin, Podcast, ScanQrCode, Send, ShoppingBag, Star, Store,
  Twitter, Wallet, Youtube,
}
export const contactIcon = (name) => CONTACT_ICONS[name] ?? Link
