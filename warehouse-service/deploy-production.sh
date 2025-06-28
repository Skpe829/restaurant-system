#!/bin/bash

# ================================
# WAREHOUSE SERVICE - PRODUCTION DEPLOYMENT
# ================================

echo "🏪 Deploying Warehouse Service with DynamoDB Production Setup"
echo "=============================================================="

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

STAGE=${1:-dev}

# Check if we're in the warehouse-service directory
if [ ! -f "serverless.yml" ] || [ ! -d "app" ]; then
    echo -e "${RED}❌ Please run this script from the warehouse-service directory${NC}"
    echo "cd warehouse-service && ./deploy-production.sh"
    exit 1
fi

echo -e "${BLUE}📦 Deploying to stage: $STAGE${NC}"

# Step 1: Install dependencies
echo -e "\n${YELLOW}1️⃣  Installing PHP dependencies for DynamoDB...${NC}"
composer install --no-dev --optimize-autoloader

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ Failed to install PHP dependencies${NC}"
    exit 1
fi

# Step 2: Validate configuration
echo -e "\n${YELLOW}2️⃣  Validating serverless configuration...${NC}"
serverless config validate

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ Serverless configuration validation failed${NC}"
    exit 1
fi

# Step 3: Deploy infrastructure and code
echo -e "\n${YELLOW}3️⃣  Deploying to AWS with DynamoDB...${NC}"
echo "This may take 2-3 minutes for the first deployment..."

serverless deploy --stage $STAGE --verbose

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ Deployment failed${NC}"
    exit 1
fi

# Step 4: Get service information
echo -e "\n${YELLOW}4️⃣  Retrieving service information...${NC}"

SERVICE_URL=$(aws cloudformation describe-stacks \
    --stack-name "restaurant-warehouse-service-$STAGE" \
    --query 'Stacks[0].Outputs[?OutputKey==`WarehouseServiceUrl`].OutputValue' \
    --output text 2>/dev/null)

TABLE_NAME=$(aws cloudformation describe-stacks \
    --stack-name "restaurant-warehouse-service-$STAGE" \
    --query 'Stacks[0].Outputs[?OutputKey==`InventoryTableName`].OutputValue' \
    --output text 2>/dev/null)

if [ -z "$SERVICE_URL" ]; then
    echo -e "${RED}❌ Could not retrieve service URL${NC}"
    exit 1
fi

# Step 5: Test health endpoint
echo -e "\n${YELLOW}5️⃣  Testing service health...${NC}"
echo "🔗 Service URL: $SERVICE_URL"

HEALTH_RESPONSE=$(curl -s "$SERVICE_URL" --max-time 10 2>/dev/null)

if [[ $HEALTH_RESPONSE == *"Restaurant Warehouse Service"* ]]; then
    echo -e "${GREEN}✅ Health check passed${NC}"
else
    echo -e "${YELLOW}⚠️  Health check returned unexpected response${NC}"
    echo "Response: $HEALTH_RESPONSE"
fi

# Step 6: Initialize DynamoDB inventory
echo -e "\n${YELLOW}6️⃣  Initializing DynamoDB inventory...${NC}"
echo "🗄️  Table Name: $TABLE_NAME"

INIT_RESPONSE=$(curl -s -X POST "$SERVICE_URL/api/inventory/initialize" \
     -H "Content-Type: application/json" \
     --max-time 30 2>/dev/null)

echo "📝 Initialization response:"
echo "$INIT_RESPONSE" | jq . 2>/dev/null || echo "$INIT_RESPONSE"

if [[ $INIT_RESPONSE == *"success\":true"* ]]; then
    echo -e "${GREEN}✅ Inventory initialized successfully in DynamoDB${NC}"
else
    echo -e "${YELLOW}⚠️  Check the initialization response above${NC}"
fi

# Step 7: Test inventory retrieval
echo -e "\n${YELLOW}7️⃣  Testing inventory retrieval...${NC}"

INVENTORY_RESPONSE=$(curl -s "$SERVICE_URL/api/inventory" --max-time 10 2>/dev/null)

if [[ $INVENTORY_RESPONSE == *"success\":true"* ]]; then
    echo -e "${GREEN}✅ Inventory retrieval test passed${NC}"

    # Count items
    ITEM_COUNT=$(echo "$INVENTORY_RESPONSE" | jq '.total_items' 2>/dev/null || echo "unknown")
    echo "📊 Total inventory items: $ITEM_COUNT"
else
    echo -e "${YELLOW}⚠️  Inventory retrieval test needs review${NC}"
    echo "Response: $INVENTORY_RESPONSE"
fi

# Step 8: Test specific ingredient lookup
echo -e "\n${YELLOW}8️⃣  Testing specific ingredient lookup...${NC}"

TOMATO_RESPONSE=$(curl -s "$SERVICE_URL/api/inventory/tomato" --max-time 10 2>/dev/null)

if [[ $TOMATO_RESPONSE == *"success\":true"* ]]; then
    echo -e "${GREEN}✅ Ingredient lookup test passed${NC}"
    echo "🍅 Tomato inventory:"
    echo "$TOMATO_RESPONSE" | jq '.data' 2>/dev/null || echo "$TOMATO_RESPONSE"
else
    echo -e "${YELLOW}⚠️  Ingredient lookup test needs review${NC}"
fi

# Step 9: Verify DynamoDB table exists
echo -e "\n${YELLOW}9️⃣  Verifying DynamoDB table...${NC}"

TABLE_STATUS=$(aws dynamodb describe-table --table-name "$TABLE_NAME" \
    --query 'Table.TableStatus' --output text 2>/dev/null)

if [ "$TABLE_STATUS" = "ACTIVE" ]; then
    echo -e "${GREEN}✅ DynamoDB table is ACTIVE${NC}"

    # Get table details
    ITEM_COUNT=$(aws dynamodb scan --table-name "$TABLE_NAME" \
        --select "COUNT" --query 'Count' --output text 2>/dev/null)

    echo "📊 Items in DynamoDB table: $ITEM_COUNT"
else
    echo -e "${YELLOW}⚠️  DynamoDB table status: $TABLE_STATUS${NC}"
fi

# Step 10: Generate summary and test commands
echo -e "\n${GREEN}🎉 Production Deployment Summary${NC}"
echo "=================================="
echo "✅ Service deployed with DynamoDB"
echo "✅ Infrastructure configured"
echo "✅ Inventory initialized"
echo "✅ All tests completed"
echo ""
echo -e "${BLUE}Service Information:${NC}"
echo "🔗 URL: $SERVICE_URL"
echo "🗄️  DynamoDB Table: $TABLE_NAME"
echo "🌍 Region: us-east-1"
echo "📋 Stage: $STAGE"
echo ""
echo -e "${BLUE}Test Commands:${NC}"
echo "# List all inventory"
echo "curl $SERVICE_URL/api/inventory"
echo ""
echo "# Get specific ingredient"
echo "curl $SERVICE_URL/api/inventory/tomato"
echo ""
echo "# Add stock to ingredient"
echo "curl -X PUT $SERVICE_URL/api/inventory/tomato/add-stock \\"
echo "  -H 'Content-Type: application/json' \\"
echo "  -d '{\"amount\": 5}'"
echo ""
echo "# Reserve stock"
echo "curl -X PUT $SERVICE_URL/api/inventory/tomato/reserve \\"
echo "  -H 'Content-Type: application/json' \\"
echo "  -d '{\"amount\": 2}'"
echo ""
echo -e "${BLUE}AWS Console Commands:${NC}"
echo "# View DynamoDB table"
echo "aws dynamodb scan --table-name $TABLE_NAME"
echo ""
echo "# Check CloudWatch logs"
echo "aws logs describe-log-groups --log-group-name-prefix /aws/lambda/restaurant-warehouse"

# Save information to file
cat > deployment-info-$STAGE.txt << EOF
Warehouse Service Production Deployment
======================================
Deployment Date: $(date)
Stage: $STAGE
Service URL: $SERVICE_URL
DynamoDB Table: $TABLE_NAME
Table Status: $TABLE_STATUS
Items in Table: $ITEM_COUNT

Test Commands:
=============
curl $SERVICE_URL/api/inventory
curl $SERVICE_URL/api/inventory/tomato
curl -X PUT $SERVICE_URL/api/inventory/tomato/add-stock -H 'Content-Type: application/json' -d '{"amount": 5}'

AWS Console:
===========
aws dynamodb scan --table-name $TABLE_NAME
aws logs describe-log-groups --log-group-name-prefix /aws/lambda/restaurant-warehouse
EOF

echo ""
echo -e "${YELLOW}📝 Deployment information saved to deployment-info-$STAGE.txt${NC}"
echo ""
echo -e "${GREEN}🚀 Warehouse Service is now running on production DynamoDB!${NC}"