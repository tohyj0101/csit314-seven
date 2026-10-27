module.exports = (...roles) => (req, res, next) => {
  if (!req.session || !req.session.user) return res.status(401).json({ error: 'Not authenticated' });
  if (!roles.includes(req.session.user.role)) return res.status(403).json({ error: 'Forbidden' });
  next();
};