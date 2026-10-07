/** Route publik: info toko untuk frontend (tanpa secret). */
import { Router } from 'express';
import { publicSettings } from '../db.js';
import { generalInquiryLink } from '../lib/whatsapp.js';

export const settingsRouter = Router();

settingsRouter.get('/public', (req, res) => {
  const s = publicSettings();
  res.json({
    settings: {
      ...s,
      whatsapp_link: s.admin_whatsapp ? generalInquiryLink(s.admin_whatsapp, s.shop_name) : '',
    },
  });
});
