#!/bin/bash

# ================================
# RESTAURANT SYSTEM - DEPLOYMENT SCRIPT MEJORADO
# ================================

set -e  # Exit on any error

echo "🚀 Restaurant Order Management System - Deployment Script v2"
echo "=============================================================="

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Set deployment stage
STAGE=${1:-dev}
echo -e "${BLUE}📦 Deploying to stage: $STAGE${NC}"

# Function to check prerequisites
check_prerequisites() {
    echo -e "\n${YELLOW}🔍 Checking prerequisites...${NC}"
    
    # Check AWS CLI
    if ! command -v aws &> /dev/null; then
        echo -e "${RED}❌ AWS CLI is not installed. Please install it first.${NC}"
        exit 1
    fi
    
    # Check Serverless Framework
    if ! command -v serverless &> /dev/null; then
        echo -e "${RED}❌ Serverless Framework is not installed.${NC}"
        echo "Run: npm install -g serverless@3"
        exit 1
    fi
    
    # Check AWS credentials
    if ! aws sts get-caller-identity &> /dev/null; then
        echo -e "${RED}❌ AWS credentials not configured properly.${NC}"
        echo "Run: aws configure"
        exit 1
    fi
    
    # Check PHP and Composer
    if ! command -v php &> /dev/null; then
        echo -e "${RED}❌ PHP is not installed.${NC}"
        exit 1
    fi
    
    if ! command -v composer &> /dev/null; then
        echo -e "${RED}❌ Composer is not installed.${NC}"
        exit 1
    fi
    
    echo -e "${GREEN}✅ All prerequisites checked${NC}"
}

# Function to deploy a service
deploy_service() {
    local service_name=$1
    local service_dir=$2
    local depends_on=$3
    
    echo -e "\n${YELLOW}🔧 Deploying $service_name...${NC}"
    
    if [ ! -d "$service_dir" ]; then
        echo -e "${RED}❌ Directory $service_dir not found${NC}"
        echo "Please create the service first:"
        echo "composer create-project laravel/laravel $service_dir"
        return 1
    fi
    
    cd "$service_dir"
    
    # Check if serverless.yml exists
    if [ ! -f "serverless.yml" ]; then
        echo -e "${RED}❌ serverless.yml not found in $service_dir${NC}"
        echo "Please copy the serverless.yml configuration for $service_name"
        cd ..
        return 1
    fi
    
    # Install PHP dependencies
    if [ -f "composer.json" ]; then
        echo "📦 Installing PHP dependencies..."
        composer install --no-dev --optimize-autoloader --quiet
        if [ $? -ne 0 ]; then
            echo -e "${RED}❌ Failed to install dependencies for $service_name${NC}"
            cd ..
            return 1
        fi
    fi
    
    # Wait for dependencies if specified
    if [ ! -z "$depends_on" ]; then
        echo "⏳ Waiting for dependency: $depends_on"
        wait_for_stack "$depends_on"
    fi
    
    # Deploy with Serverless
    echo "🚀 Deploying serverless infrastructure..."
    serverless deploy --stage $STAGE --verbose
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✅ $service_name deployed successfully${NC}"
        
        # Test the deployment
        test_service_health "$service_name"
    else
        echo -e "${RED}❌ Failed to deploy $service_name${NC}"
        cd ..
        return 1
    fi
    
    cd ..
    return 0
}

# Function to wait for a CloudFormation stack to be ready
wait_for_stack() {
    local stack_name=$1
    local max_wait=300  # 5 minutes
    local wait_time=0
    
    echo "⏳ Waiting for stack $stack_name to be ready..."
    
    while [ $wait_time -lt $max_wait ]; do
        local stack_status=$(aws cloudformation describe-stacks \
            --stack-name "$stack_name" \
            --query 'Stacks[0].StackStatus' \
            --output text 2>/dev/null || echo "STACK_NOT_FOUND")
        
        if [ "$stack_status" = "CREATE_COMPLETE" ] || [ "$stack_status" = "UPDATE_COMPLETE" ]; then
            echo -e "${GREEN}✅ Stack $stack_name is ready${NC}"
            return 0
        elif [ "$stack_status" = "CREATE_FAILED" ] || [ "$stack_status" = "UPDATE_FAILED" ]; then
            echo -e "${RED}❌ Stack $stack_name failed${NC}"
            return 1
        fi
        
        sleep 10
        wait_time=$((wait_time + 10))
        echo "⏳ Still waiting... ($wait_time/${max_wait}s)"
    done
    
    echo -e "${RED}❌ Timeout waiting for stack $stack_name${NC}"
    return 1
}

# Function to test service health
test_service_health() {
    local service_name=$1
    local stack_name=""
    
    case $service_name in
        "Order Service")
            stack_name="restaurant-order-service-$STAGE"
            ;;
        "Kitchen Service")
            stack_name="restaurant-kitchen-service-$STAGE"
            ;;
        "Warehouse Service")
            stack_name="restaurant-warehouse-service-$STAGE"
            ;;
        "Marketplace Service")
            stack_name="restaurant-marketplace-service-$STAGE"
            ;;
    esac
    
    # Get service URL
    local service_url=$(aws cloudformation describe-stacks \
        --stack-name "$stack_name" \
        --query 'Stacks[0].Outputs[0].OutputValue' \
        --output text 2>/dev/null || echo "")
    
    if [ -n "$service_url" ]; then
        echo "🔍 Testing $service_name health..."
        local response=$(curl -s -o /dev/null -w "%{http_code}" "$service_url" --max-time 10 || echo "000")
        
        if [ "$response" = "200" ]; then
            echo -e "${GREEN}✅ $service_name is healthy${NC}"
        else
            echo -e "${YELLOW}⚠️  $service_name health check returned: $response${NC}"
        fi
    fi
}

# Function to initialize inventory
initialize_inventory() {
    echo -e "\n${YELLOW}🏪 Initializing warehouse inventory...${NC}"
    
    # Get warehouse service URL
    local warehouse_url=$(aws cloudformation describe-stacks \
        --stack-name "restaurant-warehouse-service-$STAGE" \
        --query 'Stacks[0].Outputs[0].OutputValue' \
        --output text 2>/dev/null || echo "")
    
    if [ -n "$warehouse_url" ]; then
        echo "🔗 Using warehouse URL: $warehouse_url"
        
        local response=$(curl -s -X POST "$warehouse_url/api/inventory/initialize" \
             -H "Content-Type: application/json" \
             --max-time 30 || echo "ERROR")
        
        if [[ $response == *"success"* ]]; then
            echo -e "${GREEN}✅ Inventory initialized successfully${NC}"
        else
            echo -e "${YELLOW}⚠️  Could not initialize inventory automatically${NC}"
            echo "You can initialize it manually with:"
            echo "curl -X POST $warehouse_url/api/inventory/initialize"
        fi
    else
        echo -e "${YELLOW}⚠️  Could not get warehouse URL${NC}"
    fi
}

# Function to update Order Service with other service URLs
update_order_service() {
    echo -e "\n${YELLOW}🔗 Updating Order Service with other service URLs...${NC}"
    
    # The URLs are now configured via CloudFormation references in serverless.yml
    # So we just need to redeploy the Order Service
    cd order-service
    
    echo "🚀 Redeploying Order Service with updated URLs..."
    serverless deploy --stage $STAGE --verbose
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✅ Order Service updated with service URLs${NC}"
    else
        echo -e "${RED}❌ Failed to update Order Service${NC}"
    fi
    
    cd ..
}

# Function to show deployment information
show_deployment_info() {
    echo -e "\n${GREEN}📊 Deployment Information${NC}"
    echo "=========================="
    
    echo -e "\n${YELLOW}Service URLs:${NC}"
    
    # Get all service URLs
    local order_url=$(aws cloudformation describe-stacks \
        --stack-name "restaurant-order-service-$STAGE" \
        --query 'Stacks[0].Outputs[0].OutputValue' \
        --output text 2>/dev/null || echo "Not deployed")
    
    local kitchen_url=$(aws cloudformation describe-stacks \
        --stack-name "restaurant-kitchen-service-$STAGE" \
        --query 'Stacks[0].Outputs[0].OutputValue' \
        --output text 2>/dev/null || echo "Not deployed")
    
    local warehouse_url=$(aws cloudformation describe-stacks \
        --stack-name "restaurant-warehouse-service-$STAGE" \
        --query 'Stacks[0].Outputs[0].OutputValue' \
        --output text 2>/dev/null || echo "Not deployed")
    
    local marketplace_url=$(aws cloudformation describe-stacks \
        --stack-name "restaurant-marketplace-service-$STAGE" \
        --query 'Stacks[0].Outputs[0].OutputValue' \
        --output text 2>/dev/null || echo "Not deployed")
    
    echo "📋 Order Service: $order_url"
    echo "👨‍🍳 Kitchen Service: $kitchen_url"
    echo "🏪 Warehouse Service: $warehouse_url"
    echo "🛒 Marketplace Service: $marketplace_url"
    
    echo -e "\n${YELLOW}Test Commands:${NC}"
    if [ "$order_url" != "Not deployed" ]; then
        echo "# Create a test order"
        echo "curl -X POST $order_url/api/orders \\"
        echo "  -H 'Content-Type: application/json' \\"
        echo "  -d '{\"quantity\": 2, \"customer_name\": \"Test Customer\"}'"
    fi
    
    if [ "$warehouse_url" != "Not deployed" ]; then
        echo -e "\n# Check inventory"
        echo "curl $warehouse_url/api/inventory"
    fi
    
    if [ "$kitchen_url" != "Not deployed" ]; then
        echo -e "\n# View available recipes"
        echo "curl $kitchen_url/api/recipes"
    fi
    
    # Save URLs to a file
    cat > service-urls-$STAGE.txt << EOF
Order Service: $order_url
Kitchen Service: $kitchen_url
Warehouse Service: $warehouse_url
Marketplace Service: $marketplace_url
Deployment Stage: $STAGE
Deployment Time: $(date)
EOF
    
    echo -e "\n${YELLOW}📝 Service URLs saved to service-urls-$STAGE.txt${NC}"
}

# Function to run end-to-end test
run_e2e_test() {
    echo -e "\n${YELLOW}🧪 Running end-to-end test...${NC}"
    
    local order_url=$(aws cloudformation describe-stacks \
        --stack-name "restaurant-order-service-$STAGE" \
        --query 'Stacks[0].Outputs[0].OutputValue' \
        --output text 2>/dev/null || echo "")
    
    if [ -n "$order_url" ]; then
        echo "🔍 Creating test order..."
        local response=$(curl -s -X POST "$order_url/api/orders" \
             -H "Content-Type: application/json" \
             -d '{"quantity": 1, "customer_name": "E2E Test"}' \
             --max-time 30 || echo "ERROR")
        
        if [[ $response == *"success"* ]]; then
            echo -e "${GREEN}✅ End-to-end test passed${NC}"
        else
            echo -e "${YELLOW}⚠️  End-to-end test needs manual verification${NC}"
            echo "Response: $response"
        fi
    else
        echo -e "${YELLOW}⚠️  Cannot run E2E test - Order Service URL not found${NC}"
    fi
}

# Main deployment function
main() {
    echo -e "\n${BLUE}🎯 Starting deployment sequence...${NC}"
    
    # Check prerequisites
    check_prerequisites
    
    # Deploy services in order (with dependencies)
    echo -e "\n${BLUE}1️⃣  Deploying Kitchen Service...${NC}"
    deploy_service "Kitchen Service" "kitchen-service" "" || exit 1
    
    echo -e "\n${BLUE}2️⃣  Deploying Warehouse Service...${NC}"
    deploy_service "Warehouse Service" "warehouse-service" "" || exit 1
    
    echo -e "\n${BLUE}3️⃣  Deploying Marketplace Service...${NC}"
    deploy_service "Marketplace Service" "marketplace-service" "" || exit 1
    
    echo -e "\n${BLUE}4️⃣  Deploying Order Service (Orchestrator)...${NC}"
    deploy_service "Order Service" "order-service" "" || exit 1
    
    # Initialize inventory
    sleep 10  # Give services time to be ready
    initialize_inventory
    
    # Run basic tests
    run_e2e_test
    
    echo -e "\n${GREEN}🎉 Deployment completed successfully!${NC}"
    show_deployment_info
}

# Help function
show_help() {
    echo "Restaurant Order Management System - Deployment Script v2"
    echo ""
    echo "Usage: $0 [stage] [options]"
    echo ""
    echo "Arguments:"
    echo "  stage    Deployment stage (default: dev)"
    echo ""
    echo "Options:"
    echo "  --help   Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0           # Deploy to dev stage"
    echo "  $0 prod      # Deploy to prod stage"
    echo ""
    echo "Prerequisites:"
    echo "  - AWS CLI installed and configured"
    echo "  - Serverless Framework 3.x installed"
    echo "  - PHP 8.2+ with Composer"
    echo "  - All 4 Laravel projects created"
}

# Check arguments
if [ "$1" = "--help" ] || [ "$1" = "-h" ]; then
    show_help
    exit 0
fi

# Run main deployment
main