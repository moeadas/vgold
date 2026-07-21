# Victory Genomics CRM - Installation Guide for SiteGround

## 📋 System Requirements

- **Hosting**: SiteGround hosting account
- **PHP**: Version 7.4 or higher (8.0+ recommended)
- **MySQL**: Version 5.7 or higher
- **Disk Space**: Minimum 50MB
- **SSL Certificate**: Recommended (included free with SiteGround)

---

## 🚀 Quick Installation Steps

### Step 1: Upload Files

1. **Access cPanel**
   - Log into your SiteGround account
   - Go to Site Tools → File Manager

2. **Upload CRM Files**
   - Navigate to `public_html` directory (or your domain's root folder)
   - Upload all files from the `victory-genomics-crm` folder
   - Ensure proper file structure is maintained

### Step 2: Create Database

1. **Access MySQL Databases**
   - Go to Site Tools → MySQL → Databases
   - Click "Create Database"

2. **Database Setup**
   - Database Name: `victory_genomics_crm` (or your choice)
   - Click "Create"

3. **Create Database User**
   - Username: Choose a secure username
   - Password: Generate a strong password
   - Click "Create"

4. **Assign User to Database**
   - Select the database you created
   - Select the user you created
   - Grant "All Privileges"
   - Click "Make Changes"

5. **Note Down Credentials**
   ```
   Database Host: localhost
   Database Name: victory_genomics_crm
   Database User: your_username
   Database Password: your_password
   ```

### Step 3: Import Database Schema

1. **Access phpMyAdmin**
   - Go to Site Tools → MySQL → phpMyAdmin
   - Select your database from the left sidebar

2. **Import Schema**
   - Click "Import" tab
   - Click "Choose File"
   - Select `database/schema.sql`
   - Click "Go" at the bottom
   - Wait for success message

### Step 4: Configure Database Connection

1. **Edit Configuration File**
   - Open File Manager
   - Navigate to `config/database.php`
   - Click "Edit"

2. **Update Database Credentials**
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'victory_genomics_crm');  // Your database name
   define('DB_USER', 'your_db_username');      // Your database user
   define('DB_PASS', 'your_db_password');      // Your database password
   ```

3. **Update Application URL**
   ```php
   define('APP_URL', 'https://yourdomain.com');  // Your domain
   ```

4. **Save Changes**

### Step 5: Set File Permissions

1. **Create Uploads Directory**
   ```
   - Create folder: /uploads
   - Set permissions: 755
   ```

2. **Set Permissions** (if needed)
   - Right-click on folders
   - Change Permissions:
     - `/uploads` - 755 (rwxr-xr-x)
     - `/config` - 644 (rw-r--r--)

### Step 6: Test Installation

1. **Access Login Page**
   - Open browser: `https://yourdomain.com/login.php`

2. **Default Credentials**
   ```
   Username: admin
   Password: Admin@123
   ```

3. **IMPORTANT**: Change the default password immediately after first login!

---

## 🔒 Post-Installation Security

### 1. Change Default Password

1. Log in with default credentials
2. Go to "My Profile"
3. Click "Change Password"
4. Enter a strong password (min 8 characters)
5. Save changes

### 2. Create New Admin User (Optional)

1. Go to "User Management"
2. Click "Add New User"
3. Fill in details with role "Admin"
4. Save

### 3. Disable or Delete Default Admin

1. After creating new admin
2. Go to "User Management"
3. Edit the default 'admin' user
4. Change status to "Inactive" or delete

### 4. Enable HTTPS

1. Go to SiteGround Site Tools
2. Navigate to Security → SSL Manager
3. Install/Enable Let's Encrypt SSL (free)
4. Force HTTPS redirect

### 5. Update Error Reporting (Production)

Edit `config/database.php`:
```php
// Set to 0 in production
error_reporting(0);
ini_set('display_errors', 0);
```

---

## 📊 Importing Your Existing Horse Stables Data

### Option 1: Manual Import via UI

1. Log into CRM
2. Go to "Leads"
3. Click "Import" button
4. Upload your CSV/Excel file
5. Map columns
6. Complete import

### Option 2: Direct Database Import

1. Open phpMyAdmin
2. Select your database
3. Click "SQL" tab
4. Run this for each stable:

```sql
INSERT INTO leads (
    lead_type, company_name, contact_person, region, country,
    phone, email, website, facebook_url, instagram_url,
    linkedin_url, specialization, facility_type,
    lead_status, lead_source, created_by
) VALUES (
    'Stable',
    'WinStar Farm',
    'Kenny Troutt',
    'North America',
    'United States',
    '(859) 873-1717',
    'info@winstarfarm.com',
    'https://www.winstarfarm.com',
    'https://www.facebook.com/WinStarFarm/',
    '@winstarfarm',
    NULL,
    'Thoroughbred Breeding',
    'Breeding',
    'New Lead',
    'Import',
    1
);
```

---

## 👥 Creating Additional Users

1. **Log in as Admin**
2. **Navigate to User Management**
   - Sidebar → User Management

3. **Add New User**
   - Click "Add New User"
   - Fill in required fields:
     - Username (unique)
     - Email (unique)
     - Full Name
     - Password (min 8 characters)
     - Role (Admin, Sales Manager, Sales Rep, Viewer)
   - Click "Save"

4. **User Roles Explained**:
   - **Admin**: Full access to everything
   - **Sales Manager**: Manage all leads, view reports, cannot manage users
   - **Sales Rep**: Manage assigned leads only
   - **Viewer**: Read-only access

---

## 🎨 Customization

### Update Company Branding

1. **Edit Logo** (optional)
   - Replace `/assets/images/logo.png` with your logo
   - Recommended size: 200x60px

2. **Update Company Info**
   - Go to Settings → Company Profile
   - Update company name, email, phone, website

### Customize Colors (optional)

Edit `/assets/css/style.css`:
```css
:root {
    --primary-color: #667eea;      /* Your primary color */
    --secondary-color: #764ba2;    /* Your secondary color */
}
```

---

## 🔧 Troubleshooting

### Issue: White Screen / 500 Error

**Solution**:
1. Check database credentials in `config/database.php`
2. Verify database was imported correctly
3. Check PHP error logs in cPanel

### Issue: Cannot Login

**Solution**:
1. Verify database was imported (includes default admin user)
2. Clear browser cache
3. Try password reset (if implemented)

### Issue: Session Errors

**Solution**:
1. Check PHP session settings
2. Ensure `/tmp` directory is writable
3. Contact SiteGround support if persists

### Issue: File Upload Errors

**Solution**:
1. Create `/uploads` directory
2. Set permissions to 755
3. Check PHP `upload_max_filesize` setting

### Issue: Slow Performance

**Solution**:
1. Enable caching in SiteGround
2. Optimize database (run OPTIMIZE TABLE)
3. Consider upgrading hosting plan

---

## 📧 Support & Assistance

### Technical Support
- **SiteGround Support**: 24/7 via chat/phone
- **CRM Documentation**: [Coming Soon]
- **Victory Genomics**: support@victorygenomics.com

### Useful SiteGround Links
- [Site Tools Guide](https://www.siteground.com/tutorials/cpanel/)
- [MySQL Management](https://www.siteground.com/tutorials/cpanel/mysql/)
- [SSL Certificate Setup](https://www.siteground.com/kb/install-ssl/)

---

## 📝 Next Steps After Installation

1. ✅ Change default admin password
2. ✅ Create additional user accounts for your team
3. ✅ Import your 150+ horse stables database
4. ✅ Customize company settings
5. ✅ Set up email templates
6. ✅ Configure user permissions
7. ✅ Train team on CRM usage
8. ✅ Start adding interactions and tracking leads

---

## 🎯 Key Features to Explore

- **Dashboard**: Real-time statistics and activity feed
- **Lead Management**: Add, edit, filter, and search leads
- **Status Tracking**: Move leads through sales pipeline
- **Interactions**: Log calls, emails, meetings
- **User Management**: Control team access and permissions
- **Reports**: Track performance metrics
- **Export**: Download data to CSV/Excel

---

## ⚠️ Important Notes

1. **Backup Regularly**: Use SiteGround's backup features
2. **Keep Updated**: Check for CRM updates periodically
3. **Monitor Security**: Review activity logs regularly
4. **Data Privacy**: Comply with GDPR/data protection laws
5. **Test First**: Try features on test leads before going live

---

## 📞 Emergency Recovery

If something goes wrong:

1. **Restore from Backup**
   - SiteGround Site Tools → Backup
   - Choose recent backup
   - Restore files and database

2. **Fresh Installation**
   - Delete all CRM files
   - Drop database
   - Start installation from Step 1

3. **Contact Support**
   - SiteGround technical support
   - Victory Genomics support team

---

**Installation Complete! 🎉**

Your Victory Genomics CRM is now ready to help you manage your horse stable leads and close more deals!

---

*Last Updated: February 2026*
*Version: 1.0.0*
