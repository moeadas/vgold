# Victory Genomics CRM 🧬

> A comprehensive Customer Relationship Management system built specifically for Victory Genomics to manage horse stable leads, contacts, and sales pipeline.

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)
![License](https://img.shields.io/badge/license-Proprietary-red.svg)

---

## 🎯 Features

### Lead Management
- ✅ Comprehensive lead database (Stables, Owners, Breeders, Trainers, etc.)
- ✅ Advanced filtering by region, country, type, status
- ✅ Contact management with social media integration
- ✅ Priority and status tracking
- ✅ Lead assignment to sales team members

### Sales Pipeline
- ✅ Visual status workflow (New Lead → Won/Lost)
- ✅ Automated status tracking
- ✅ Deal progression monitoring
- ✅ Performance metrics

### Interaction Tracking
- ✅ Log all communications (calls, emails, meetings)
- ✅ Schedule follow-ups
- ✅ Activity timeline
- ✅ Next action reminders

### User Management
- ✅ Role-based access control (Admin, Sales Manager, Sales Rep, Viewer)
- ✅ User activity tracking
- ✅ Team collaboration features
- ✅ Secure authentication

### Analytics & Reporting
- ✅ Real-time dashboard
- ✅ Sales performance metrics
- ✅ Regional distribution analysis
- ✅ Export to CSV/Excel

### Security
- ✅ Password encryption (bcrypt)
- ✅ Session management
- ✅ Activity logging
- ✅ Role-based permissions
- ✅ SQL injection protection

---

## 📋 Requirements

- **Web Server**: Apache with mod_rewrite
- **PHP**: 7.4 or higher (8.0+ recommended)
- **MySQL**: 5.7 or higher
- **Hosting**: SiteGround (optimized) or any cPanel hosting
- **SSL**: Recommended for production
- **Disk Space**: Minimum 50MB

---

## 🚀 Installation

See **[INSTALLATION_GUIDE.md](./INSTALLATION_GUIDE.md)** for detailed step-by-step instructions.

### Quick Start

1. **Upload files** to your hosting server
2. **Create MySQL database** and user
3. **Import** `database/schema.sql`
4. **Configure** `config/database.php` with your credentials
5. **Access** `https://yourdomain.com/login.php`
6. **Login** with default credentials:
   - Username: `admin`
   - Password: `Admin@123`
7. **Change password** immediately after first login

---

## 📂 Project Structure

```
victory-genomics-crm/
├── api/                    # API endpoints
│   ├── leads.php          # Lead CRUD operations
│   ├── interactions.php   # Interaction management
│   └── export.php         # Data export functionality
├── assets/                # Static assets
│   ├── css/
│   │   └── style.css     # Main stylesheet
│   └── js/
│       └── main.js       # Main JavaScript
├── config/                # Configuration files
│   └── database.php      # Database configuration
├── database/              # Database schemas
│   └── schema.sql        # Initial database structure
├── includes/              # PHP includes
│   ├── auth.php          # Authentication functions
│   ├── functions.php     # Helper functions
│   ├── header.php        # Page header
│   └── footer.php        # Page footer
├── pages/                 # Application pages
│   ├── leads.php         # Lead management
│   ├── lead-detail.php   # Lead details
│   ├── interactions.php  # Interactions
│   ├── reports.php       # Reports & analytics
│   ├── users.php         # User management (Admin)
│   └── settings.php      # System settings (Admin)
├── uploads/               # File uploads directory
├── .htaccess             # Apache configuration
├── login.php             # Login page
├── dashboard.php         # Main dashboard
├── logout.php            # Logout handler
├── INSTALLATION_GUIDE.md # Installation instructions
└── README.md             # This file
```

---

## 👥 User Roles & Permissions

| Feature | Admin | Sales Manager | Sales Rep | Viewer |
|---------|-------|---------------|-----------|--------|
| View All Leads | ✅ | ✅ | ❌ (Own only) | ✅ |
| Add/Edit Leads | ✅ | ✅ | ✅ (Own only) | ❌ |
| Delete Leads | ✅ | ✅ | ❌ | ❌ |
| Assign Leads | ✅ | ✅ | ❌ | ❌ |
| User Management | ✅ | ❌ | ❌ | ❌ |
| System Settings | ✅ | ❌ | ❌ | ❌ |
| View Reports | ✅ | ✅ | ✅ (Own data) | ✅ |
| Export Data | ✅ | ✅ | ✅ | ❌ |

---

## 🔐 Security Features

- **Password Hashing**: bcrypt with cost factor 10
- **Session Security**: HTTP-only cookies, secure flags
- **SQL Injection Protection**: Prepared statements (PDO)
- **XSS Protection**: Output escaping
- **CSRF Protection**: Token verification
- **Activity Logging**: Complete audit trail
- **Access Control**: Role-based permissions

---

## 📊 Database Schema

### Main Tables
- **users**: User accounts and authentication
- **leads**: Lead/contact information
- **interactions**: Communication history
- **documents**: File attachments
- **activity_log**: System audit log
- **settings**: System configuration

---

## 🎨 Design & Branding

- **Color Scheme**: Purple gradient (Victory Genomics brand)
- **Framework**: Custom CSS (responsive)
- **Icons**: Font Awesome 6
- **Typography**: Inter font family
- **Mobile**: Fully responsive design

---

## 🔧 Customization

### Update Branding
Edit `/assets/css/style.css`:
```css
:root {
    --primary-color: #667eea;
    --secondary-color: #764ba2;
}
```

### Add Custom Fields
1. Modify database schema
2. Update form views
3. Update API endpoints

---

## 📱 Browser Support

- ✅ Chrome (latest)
- ✅ Firefox (latest)
- ✅ Safari (latest)
- ✅ Edge (latest)
- ✅ Mobile browsers (iOS Safari, Chrome Mobile)

---

## 🐛 Troubleshooting

### Common Issues

**Issue**: Cannot connect to database
- Check `config/database.php` credentials
- Verify MySQL service is running
- Check database user permissions

**Issue**: Session errors
- Verify session directory is writable
- Check PHP session settings
- Clear browser cookies

**Issue**: File upload fails
- Create `/uploads` directory
- Set permissions to 755
- Check PHP upload settings

See [INSTALLATION_GUIDE.md](./INSTALLATION_GUIDE.md) for more solutions.

---

## 📈 Roadmap

### Upcoming Features
- [ ] Email integration (send emails from CRM)
- [ ] Calendar integration
- [ ] Advanced reporting dashboard
- [ ] Mobile app
- [ ] API for third-party integrations
- [ ] WhatsApp integration
- [ ] Automated follow-up reminders
- [ ] Lead scoring system

---

## 📞 Support

For technical support or questions:
- **Email**: support@victorygenomics.com
- **Website**: https://victorygenomics.com

---

## 📄 License

This software is proprietary and confidential.
Unauthorized copying, distribution, or use is strictly prohibited.

© 2026 Victory Genomics. All rights reserved.

---

## ✨ Credits

Developed specifically for Victory Genomics by AI Development Team

**Special Thanks**:
- SiteGround for reliable hosting
- Font Awesome for icons
- jQuery for JavaScript utilities

---

**Version**: 1.0.0  
**Last Updated**: February 2026  
**Status**: Production Ready
