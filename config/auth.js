const bcrypt = require('bcryptjs');
exports.hashPassword = (p) => bcrypt.hash(p, 10);
exports.verifyPassword = (p, h) => bcrypt.compare(p, h);