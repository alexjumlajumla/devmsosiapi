# S3 Setup Instructions for Voice Recording Feature

## Bucket Configuration

Your S3 bucket is currently configured with "bucket owner enforced" Object Ownership settings, which doesn't allow ACL operations. The code has been updated to work with this setting. 

For your app to work properly with S3, follow these steps:

## 1. Add CORS Configuration

Add a CORS (Cross-Origin Resource Sharing) configuration to your S3 bucket to allow browser-based uploads:

1. Go to your S3 bucket in the AWS Console
2. Click the "Permissions" tab
3. Scroll down to "Cross-origin resource sharing (CORS)"
4. Click "Edit"
5. Add the following configuration:

```json
[
    {
        "AllowedHeaders": [
            "*"
        ],
        "AllowedMethods": [
            "GET",
            "PUT",
            "POST",
            "DELETE",
            "HEAD"
        ],
        "AllowedOrigins": [
            "*"
        ],
        "ExposeHeaders": [
            "ETag"
        ],
        "MaxAgeSeconds": 3000
    }
]
```

**Note:** For production, you should replace `"*"` in AllowedOrigins with your specific domains like `"https://yourdomain.com"`, `"https://www.yourdomain.com"`.

## 2. Bucket Policy for Public Access

Since you're not using ACLs for public access, you need to set up a bucket policy to make objects publicly readable:

1. Go to your S3 bucket in the AWS Console
2. Click the "Permissions" tab
3. Under "Bucket policy", click "Edit"
4. Add the following policy (replace `msosijumla` with your bucket name):

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "PublicReadForGetBucketObjects",
            "Effect": "Allow",
            "Principal": "*",
            "Action": "s3:GetObject",
            "Resource": "arn:aws:s3:::msosijumla/*"
        }
    ]
}
```

## 3. Testing

After applying these settings, you should be able to:
1. Upload audio files from the browser to S3
2. Access the uploaded files via their public URLs

## Troubleshooting

If you still have issues:

1. Verify AWS credentials in `.env.local` are correct
2. Check that the bucket name in the code matches your actual bucket name
3. Try uploading a file using the AWS CLI to confirm bucket permissions
4. Check browser network tab for specific error messages during upload 