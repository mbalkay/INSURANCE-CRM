# Insurance CRM v1.9.0 - Enhanced Color Customization System

## Overview
Version 1.9.0 introduces a comprehensive color customization system that preserves the original purple gradient theme while allowing extensive customization of all UI components.

## Key Features

### 🎨 Preserved Purple Gradient Theme
The original purple gradient theme is maintained as the default throughout the system:
- Primary colors: Purple gradient (`#6c5ce7`, `#a29bfe`)
- Accent colors: Light blue (`#74b9ff`) and pink (`#fd79a8`)
- Professional appearance with modern gradient design

### 🛠️ Boss-Level Global Customization
Administrators can customize:
- **Primary Color**: Main buttons and form elements
- **Secondary Color**: Special panels and birthday widgets
- **Header Color**: Page titles and main headers
- **Submenu Color**: Navigation tabs and secondary menus
- **Button Color**: Action buttons and interactive elements
- **Accent Color**: Status indicators and highlights
- **Link Color**: Text links and navigation
- **Background Color**: Main page background
- **Sidebar Color**: Left menu and navigation panels

### 👤 User-Level Panel Customization
Each user can customize their panel colors:
- **Personal**: Individual customer panels
- **Corporate**: Business customer panels
- **Family**: Family insurance panels
- **Vehicle**: Auto insurance panels
- **Home**: Property insurance panels

## Technical Implementation

### CSS Custom Properties
Enhanced `representative-panel-global.css` with CSS custom properties:
```css
:root {
    --primary-500: #6c5ce7;
    --header-color: #6c5ce7;
    --submenu-color: #74b9ff;
    --button-color: #a29bfe;
    --accent-color: #fd79a8;
    /* ... and more */
}
```

### Global Theme System
New global theme CSS in `template-colors.php`:
- Automatic application of boss settings to all components
- Real-time color preview functionality
- Responsive design support
- Accessibility considerations

### JavaScript Enhancements
- Real-time color preview in boss settings
- Enhanced reset functionality with improved defaults
- Automatic color value updates in UI

## File Changes

### Updated Files:
1. **insurance-crm.php** - Version update to 1.9.0
2. **template-colors.php** - Enhanced global theme system
3. **boss_settings.php** - Additional color controls and preview
4. **settings.php** - Theme information and enhanced reset
5. **representative-panel-global.css** - CSS custom properties

### New Features:
- Expandable theme infrastructure
- Real-time color preview
- Theme information section
- Enhanced purple gradient defaults
- Comprehensive component coverage

## Usage

### For Administrators:
1. Access boss settings panel
2. Navigate to "Site Görünümü" tab
3. Customize colors using color pickers
4. Preview changes in real-time
5. Save settings to apply globally

### For Users:
1. Access personal settings
2. Go to "Görünüm" tab
3. Customize panel colors
4. Use "Varsayılan Renklere Dön" for purple theme reset
5. Save personal preferences

## Compatibility
- Maintains backward compatibility
- Preserves existing user preferences
- Responsive design support
- Cross-browser compatibility

## Version History
- **v1.9.0**: Enhanced color customization system
- **v1.8.x**: Basic color support
- **v1.1.0**: Initial template colors