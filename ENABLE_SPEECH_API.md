# How to Enable Google Cloud Speech-to-Text API

Based on our tests, we found that the Google Cloud Speech-to-Text API is **disabled** for your project. This is why the voice transcription stopped working.

## Steps to Enable the API:

1. Go to the Google Cloud Console:
   https://console.developers.google.com/apis/api/speech.googleapis.com/overview?project=853837987746

2. Sign in with the Google account that owns this project

3. Click the "Enable" button to activate the Speech-to-Text API for your project

4. Wait a few minutes for the activation to propagate through Google's systems

5. Return to your application and test the voice ordering again

## Verify API Status

After enabling the API, you can verify its status by running the test script:

```bash
php test-google-speech.php
```

If successful, you should see a message that says:
```
API responded successfully!
Google Cloud Speech API test completed successfully!
```

## Additional Notes

- The credentials file is correctly located and readable by the application
- No code changes are necessary - it's just an API activation issue
- If you're using the Google Cloud Console for the first time with this project, you may need to accept terms of service and set up billing
- Speech-to-Text API has usage costs - make sure your billing is set up correctly

## Troubleshooting

If you continue to have issues after enabling the API:

1. **Billing**: Ensure that billing is enabled for your Google Cloud project
2. **API Quotas**: Check if you've hit any API quotas or limits
3. **Service Account Permissions**: Verify your service account has the correct roles/permissions
4. **Wait Time**: Sometimes it takes 5-10 minutes for API activation to fully propagate 