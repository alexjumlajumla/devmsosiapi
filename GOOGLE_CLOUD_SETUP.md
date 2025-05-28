# Google Cloud Credentials Setup Guide

This guide will help you set up Google Cloud credentials for the Speech-to-Text API, which is used for voice order functionality in our application.

## Step 1: Create a Google Cloud Project

1. Go to the [Google Cloud Console](https://console.cloud.google.com/)
2. Click on "Select a project" at the top of the page
3. Click on "NEW PROJECT" in the modal window
4. Enter a project name (e.g., "MsosiVoiceOrder")
5. Click "CREATE"

## Step 2: Enable the Speech-to-Text API

1. Select your new project from the project selector at the top of the page
2. Go to "APIs & Services" > "Library" from the navigation menu
3. Search for "Speech-to-Text API"
4. Click on the "Speech-to-Text API" card
5. Click "ENABLE"

## Step 3: Create Service Account and Download Credentials

1. Go to "APIs & Services" > "Credentials" from the navigation menu
2. Click "CREATE CREDENTIALS" and select "Service account"
3. Enter a service account name (e.g., "msosi-voice-service")
4. Click "CREATE AND CONTINUE"
5. For the role, select "Project" > "Editor" (or a more restricted role if preferred)
6. Click "CONTINUE"
7. Click "DONE"
8. On the Credentials page, find the service account you just created
9. Click on the service account email address
10. Go to the "KEYS" tab
11. Click "ADD KEY" > "Create new key"
12. Select "JSON" and click "CREATE"
13. The credentials file will be downloaded to your computer

## Step 4: Set Up Environment Variables

1. Move the downloaded JSON credentials file to a secure location on your server
2. Copy the following variables to your `.env.local` file:

```
GOOGLE_APPLICATION_CREDENTIALS="/absolute/path/to/your-credentials-file.json"
GOOGLE_CLOUD_PROJECT_ID="your-project-id"
```

Replace `/absolute/path/to/your-credentials-file.json` with the actual path to your credentials file, and `your-project-id` with your Google Cloud project ID.

## Step 5: Verify Your Setup

1. Go to the voice test page in your application
2. Click the "Test Credentials" button
3. You should see a success message with details about your credentials

## Troubleshooting

If you're still experiencing issues:

1. **Invalid Credentials File**: Make sure the credentials file is valid JSON and contains all required fields
2. **File Permissions**: Ensure the web server has read access to the credentials file
3. **API Not Enabled**: Double-check that the Speech-to-Text API is enabled in your Google Cloud project
4. **Billing**: Ensure billing is enabled for your Google Cloud project
5. **Service Account Permissions**: Verify the service account has the necessary permissions

## Additional Resources

- [Google Cloud Speech-to-Text Documentation](https://cloud.google.com/speech-to-text/docs)
- [Google Cloud Authentication](https://cloud.google.com/docs/authentication)
- [Service Account Roles](https://cloud.google.com/iam/docs/understanding-roles) 